<?php
/**
 * Recursive Descent Template Parser for VueCalc SFC
 * 
 * Replaces the regex-based parsing in the old sfc-compiler.php (L111-227).
 * 
 * Architecture:
 *   1. Tokenize:    template string → Token[]  (lexer)
 *   2. Parse:       Token[] → AppNode           (recursive descent)
 *   3. Lower:       AppNode → layout arrays     (code generation prep)
 * 
 * Error handling: collects TemplateParseError[] with line numbers.
 * Unknown tags produce UnknownNode in the AST rather than being silently ignored.
 */

require_once __DIR__ . '/ast-nodes.php';
require_once __DIR__ . '/css-mappings.php';
require_once __DIR__ . '/component-registry.php';
require_once __DIR__ . '/flex-layout.php';

// ============================================================
// Token types and Token class
// ============================================================

define('TOK_EOF',        0);
define('TOK_TAG_OPEN',   1);  // <tagname ...>
define('TOK_TAG_CLOSE',  2);  // </tagname>
define('TOK_TAG_SELF',   3);  // <tagname ... />
define('TOK_TEXT',       4);  // whitespace / non-tag content
define('TOK_COMMENT',    5);  // <!-- ... -->

class Token
{
    public int $type;
    public string $content;   // Raw token text including <...>
    public int $line;

    public function __construct(int $type, string $content, int $line)
    {
        $this->type    = $type;
        $this->content = $content;
        $this->line    = $line;
    }
}

class TemplateParseError
{
    public string $message;
    public int $line;

    public function __construct(string $message, int $line)
    {
        $this->message = $message;
        $this->line    = $line;
    }

    public function __toString(): string
    {
        return "Line {$this->line}: {$this->message}";
    }
}

class TemplateParser
{
    /** @var Token[] */
    private array $tokens = [];
    private int $pos = 0;

    /** @var TemplateParseError[] */
    private array $errors = [];

    /** v5 M2: Optional component registry for resolving custom tags */
    private ?ComponentRegistry $componentRegistry = null;

    public function __construct(?ComponentRegistry $registry = null)
    {
        $this->componentRegistry = $registry;
    }

    // ============================================================
    // Public API
    // ============================================================

    /**
     * Parse a template string into an AppNode AST.
     * 
     * @param string $template  Content of <template>...</template> block
     * @return AppNode
     */
    public function parse(string $template): AppNode
    {
        $this->errors = [];
        $this->tokens = $this->tokenize($template);
        $this->pos    = 0;

        $app = $this->parseDocument();

        // Check for unclosed tags / leftover tokens
        if ($this->pos < count($this->tokens)) {
            $tok = $this->tokens[$this->pos];
            if ($tok->type !== TOK_EOF && ($tok->type !== TOK_TEXT || trim($tok->content) !== '')) {
                $this->error("Unexpected content after </app>", $tok->line);
            }
        }

        return $app;
    }

    /**
     * @return TemplateParseError[]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Pretty-print the AST for debugging (--dump-ast mode).
     */
    public function dumpAst(AppNode $app): string
    {
        return json_encode($this->astToArray($app), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    // ============================================================
    // Tokenizer (Lexer)
    // ============================================================

    /**
     * Split template text into tokens, tracking line numbers.
     * Strategy: match all tag-like and comment constructs with preg_split.
     */
    private function tokenize(string $template): array
    {
        $tokens = [];
        $line   = 1;
        $len    = strlen($template);
        $i      = 0;

        while ($i < $len) {
            // Count newlines for line tracking
            if ($template[$i] === "\n") {
                $line++;
                $i++;
                continue;
            }
            if ($template[$i] === "\r") {
                $i++;
                if ($i < $len && $template[$i] === "\n") {
                    $line++;
                    $i++;
                }
                continue;
            }

            // Skip whitespace (but not newlines, handled above)
            if ($template[$i] === ' ' || $template[$i] === "\t") {
                $i++;
                continue;
            }

            // Check for comment <!-- ... -->
            if ($i + 3 < $len && substr($template, $i, 4) === '<!--') {
                $end = strpos($template, '-->', $i + 4);
                if ($end === false) {
                    $this->error('Unclosed comment', $line);
                    break;
                }
                $comment = substr($template, $i, $end + 3 - $i);
                $newlines = substr_count($comment, "\n");
                $tokens[] = new Token(TOK_COMMENT, $comment, $line);
                $line += $newlines;
                $i = $end + 3;
                continue;
            }

            // Tag: starts with '<'
            if ($template[$i] === '<') {
                $end = strpos($template, '>', $i);
                if ($end === false) {
                    $this->error('Unclosed tag starting with "<"', $line);
                    break;
                }
                $tagText = substr($template, $i, $end + 1 - $i);

                // Determine tag type
                if (strlen($tagText) >= 3 && $tagText[1] === '/') {
                    // Closing tag: </tagname>
                    $tokens[] = new Token(TOK_TAG_CLOSE, $tagText, $line);
                } elseif (strlen($tagText) >= 3 && $tagText[strlen($tagText) - 2] === '/') {
                    // Self-closing tag: <tagname ... />
                    $tokens[] = new Token(TOK_TAG_SELF, $tagText, $line);
                } else {
                    // Opening tag: <tagname ...>
                    $tokens[] = new Token(TOK_TAG_OPEN, $tagText, $line);
                }

                $newlines = substr_count($tagText, "\n");
                $line += $newlines;
                $i = $end + 1;
                continue;
            }

            // Non-whitespace text between tags (skip, but track newlines)
            $i++;
        }

        $tokens[] = new Token(TOK_EOF, '', $line);
        return $tokens;
    }

    // ============================================================
    // Parser: Recursive Descent
    // ============================================================

    private function parseDocument(): AppNode
    {
        $this->skipUntilTag();

        // Expect <app> as root
        $tok = $this->current();
        if ($tok->type === TOK_EOF) {
            $this->error('Empty template: missing <app> root element', $tok->line);
            return new AppNode('Untitled', 336, 430, $tok->line);
        }

        $tagName = $this->getTagName($tok->content);

        if ($tagName !== 'app' || $tok->type !== TOK_TAG_OPEN) {
            $this->error("Expected <app> as root element, got <$tagName>", $tok->line);
            return new AppNode('Untitled', 336, 430, $tok->line);
        }

        return $this->parseApp($tok);
    }

    private function parseApp(Token $openTok): AppNode
    {
        $attrs = $this->parseAttrs($openTok->content);

        $title  = $attrs['title'] ?? 'Untitled';
        // 支持 w/h 和 width/height 两种语法
        $width  = (int)($attrs['width'] ?? $attrs['w'] ?? 336);
        $height = (int)($attrs['height'] ?? $attrs['h'] ?? 430);

        if (!isset($attrs['width']) && !isset($attrs['w'])) {
            $this->error('<app> missing required attribute: width (or w)', $openTok->line);
        }
        if (!isset($attrs['height']) && !isset($attrs['h'])) {
            $this->error('<app> missing required attribute: height (or h)', $openTok->line);
        }

        $app = new AppNode($title, $width, $height, $openTok->line);
        $this->advance(); // consume <app>

        // Parse children until </app>
        while (true) {
            $tok = $this->current();

            if ($tok->type === TOK_EOF) {
                $this->error('Unclosed <app> element (missing </app>)', $openTok->line);
                break;
            }

            if ($tok->type === TOK_TAG_CLOSE) {
                $closeName = $this->getTagName($tok->content);
                if ($closeName === 'app') {
                    $this->advance(); // consume </app>
                    break;
                }
                $this->error("Unexpected closing tag </$closeName> (expected </app>)", $tok->line);
                $this->advance();
                continue;
            }

            if ($tok->type === TOK_TAG_OPEN || $tok->type === TOK_TAG_SELF) {
                $child = $this->parseElement();
                if ($child !== null) {
                    $app->children[] = $child;
                }
                continue;
            }

            // Skip comments, text, etc.
            $this->advance();
        }

        return $app;
    }

    /**
     * Parse a child element of <app>: rect, text, grid, or unknown.
     */
    private function parseElement(): ?TemplateNode
    {
        $tok = $this->current();
        $tagName = $this->getTagName($tok->content);
        $tagType = $tok->type;

        switch ($tagName) {
            case 'rect':
                return $this->parseRect($tok);

            case 'text':
                return $this->parseText($tok);

            case 'grid':
                if ($tagType === TOK_TAG_SELF) {
                    $this->error('<grid> cannot be self-closing (must contain <btn> children)', $tok->line);
                    $this->advance();
                    return null;
                }
                return $this->parseGrid($tok);

            case 'textbox':
                // v6 M4: TextBox input element
                return $this->parseTextBox($tok);

            case 'btn':
                $this->error('<btn> must be inside <grid>, not directly in <app>', $tok->line);
                $this->advance();
                return null;

            case 'template':
                // v6 M3: v-for support
                if ($tagType === TOK_TAG_SELF) {
                    $this->error('<template> for v-for cannot be self-closing', $tok->line);
                    $this->advance();
                    return null;
                }
                return $this->parseTemplate($tok);

            case 'flex':
                // v6 M4: Flex container support
                return $this->parseFlex($tok);

            case 'list-item':
                // v6 M5: List item element (inside v-for)
                return $this->parseListItem($tok);

            case 'scroll-container':
                // v6 M5: Scrollable container
                return $this->parseScrollContainer($tok);

            default:
                // v5 M2: Check component registry before reporting unknown
                if ($this->componentRegistry !== null) {
                    $compFile = $this->componentRegistry->resolve($tagName);
                    if ($compFile !== null) {
                        return $this->parseComponentRef($tok, $tagName, $compFile);
                    }
                }
                // Unknown tag: report but include in AST
                $this->error("Unknown element <$tagName> — only app/rect/text/textbox/grid/btn/template/flex are supported", $tok->line);
                $node = new UnknownNode($tagName, $tok->line);
                $this->advance();
                return $node;
        }
    }

    private function parseRect(Token $tok): RectNode
    {
        $attrs = $this->parseAttrs($tok->content);
        $this->advance();

        $x    = (int)($attrs['x'] ?? 0);
        $y    = (int)($attrs['y'] ?? 0);
        $w    = (int)($attrs['w'] ?? 0);
        $h    = (int)($attrs['h'] ?? 0);
        $cls  = $attrs['class'] ?? '';
        $vIf  = $attrs['v-if'] ?? '';   // v4 M2.5
        $click = $attrs['@click'] ?? ''; // v6 M5: clickable rect

        if ($w === 0 || $h === 0) {
            $this->error("<rect> has zero width or height", $tok->line);
        }
        if ($cls === '') {
            $this->error("<rect> missing class attribute", $tok->line);
        }

        $node = new RectNode($x, $y, $w, $h, $cls, $tok->line);
        $node->vIf = $vIf;
        $node->clickHandler = $click;
        return $node;
    }

    private function parseText(Token $tok): TextNode
    {
        $attrs = $this->parseAttrs($tok->content);
        $this->advance();

        $x      = (int)($attrs['x'] ?? 0);
        $y      = (int)($attrs['y'] ?? 0);
        $bind   = $attrs[':bind'] ?? '';
        $vModel = $attrs['v-model'] ?? '';   // v4 M2.4
        $vIf    = $attrs['v-if'] ?? '';      // v4 M2.5
        $cls    = $attrs['class'] ?? '';
        $align  = $attrs['align'] ?? 'left';
        $contW  = (int)($attrs['container-w'] ?? 0);
        $contX  = (int)($attrs['container-x'] ?? 0);

        // v4 M2.4: v-model and :bind are mutually exclusive
        if ($vModel !== '' && $bind !== '') {
            $this->error("<text> cannot have both v-model and :bind — they are mutually exclusive", $tok->line);
        }

        // v4 M2.4: v-model implies :bind
        if ($vModel !== '') {
            $bind = $vModel;
        }

        if ($bind === '') {
            $this->error('<text> has no :bind or v-model attribute (will never render text)', $tok->line);
        }
        if ($cls === '') {
            $this->error('<text> missing class attribute', $tok->line);
        }

        $node = new TextNode($x, $y, $bind, $cls, $align, $contW, $contX, $tok->line, $vModel);
        $node->vIf = $vIf;
        return $node;
    }

    private function parseGrid(Token $openTok): GridNode
    {
        $attrs = $this->parseAttrs($openTok->content);
        $this->advance(); // consume <grid>

        $x      = (int)($attrs['x'] ?? 0);
        $y      = (int)($attrs['y'] ?? 0);
        $cols   = (int)($attrs['cols'] ?? 4);
        $rows   = (int)($attrs['rows'] ?? 5);
        $cellW  = (int)($attrs['cell-w'] ?? 80);
        $cellH  = (int)($attrs['cell-h'] ?? 60);
        $margin = (int)($attrs['margin'] ?? 4);
        $vIf    = $attrs['v-if'] ?? '';   // v4 M2.5

        $grid = new GridNode($x, $y, $cols, $rows, $cellW, $cellH, $margin, $openTok->line);
        $grid->vIf = $vIf;

        // Parse <btn> children until </grid>
        while (true) {
            $tok = $this->current();

            if ($tok->type === TOK_EOF) {
                $this->error('Unclosed <grid> (missing </grid>)', $openTok->line);
                break;
            }

            if ($tok->type === TOK_TAG_CLOSE) {
                $closeName = $this->getTagName($tok->content);
                if ($closeName === 'grid') {
                    $this->advance(); // consume </grid>
                    break;
                }
                $this->error("Unexpected closing tag </$closeName> inside <grid>", $tok->line);
                $this->advance();
                continue;
            }

            if ($tok->type === TOK_TAG_SELF) {
                $childName = $this->getTagName($tok->content);
                if ($childName === 'btn') {
                    $grid->buttons[] = $this->parseBtn($tok);
                } else {
                    $this->error("<$childName> not allowed inside <grid> (only <btn> supported)", $tok->line);
                    $this->advance();
                }
                continue;
            }

            if ($tok->type === TOK_TAG_OPEN) {
                $childName = $this->getTagName($tok->content);
                $this->error("<$childName> not allowed inside <grid> (only <btn> supported)", $tok->line);
                $this->advance();
                continue;
            }

            // Skip comments / text
            $this->advance();
        }

        return $grid;
    }

    /**
     * v6 M3: Parse <template v-for="item in items" :key="item.id">
     * Returns a ForNode that wraps the iterated children.
     */
    private function parseTemplate(Token $openTok): ForNode
    {
        $attrs = $this->parseAttrs($openTok->content);
        $this->advance(); // consume <template>

        // Parse v-for attribute: "item in items" or "(item, index) in items"
        $vFor = $attrs['v-for'] ?? '';
        $keyExpr = $attrs[':key'] ?? '';

        $itemVar = '';
        $sourceExpr = '';

        if ($vFor !== '') {
            // Match: "item in items" or "(item, index) in items"
            if (preg_match('/^\s*(?:\((\w+)(?:,\s*\w+)?\s*)\s+in\s+(\S+)\s*$/', $vFor, $m)) {
                $itemVar = $m[1];
                $sourceExpr = $m[2];
            }
        }

        $forNode = new ForNode($itemVar, $sourceExpr, $keyExpr, $openTok->line);

        // Parse children until </template>
        while (true) {
            $tok = $this->current();

            if ($tok->type === TOK_EOF) {
                $this->error('Unclosed <template> (missing </template>)', $openTok->line);
                break;
            }

            if ($tok->type === TOK_TAG_CLOSE) {
                $closeName = $this->getTagName($tok->content);
                if ($closeName === 'template') {
                    $this->advance(); // consume </template>
                    break;
                }
                $this->error("Unexpected closing tag </$closeName> inside <template>", $tok->line);
                $this->advance();
                continue;
            }

            if ($tok->type === TOK_TAG_OPEN || $tok->type === TOK_TAG_SELF) {
                $child = $this->parseElement();
                if ($child !== null) {
                    $forNode->children[] = $child;
                }
                continue;
            }

            // Skip comments, text, etc.
            $this->advance();
        }

        return $forNode;
    }

    /**
     * v6 M4: Parse <flex> container
     *
     * Syntax:
     *   <flex direction="column" gap="8" justify="center" align="stretch">
     *     <rect ... />
     *     <rect ... />
     *   </flex>
     */
    private function parseFlex(Token $openTok): FlexNode
    {
        $attrs = $this->parseAttrs($openTok->content);

        $x = (int)($attrs['x'] ?? 0);
        $y = (int)($attrs['y'] ?? 0);
        $w = (int)($attrs['w'] ?? 0);
        $h = (int)($attrs['h'] ?? 0);
        $direction = $attrs['direction'] ?? 'row';
        $gap = (int)($attrs['gap'] ?? 0);
        $justify = $attrs['justify'] ?? 'flex-start';
        $align = $attrs['align'] ?? 'stretch';
        $wrap = $attrs['wrap'] ?? 'nowrap';

        $node = new FlexNode($x, $y, $w, $h, $direction, $gap, $justify, $align, $wrap, $openTok->line);
        $node->class = $attrs['class'] ?? '';

        // CRITICAL: Must advance past the <flex> opening tag before parsing children
        $this->advance();

        // Parse children until </flex>
        while (true) {
            $tok = $this->current();

            if ($tok->type === TOK_EOF) {
                $this->error('Unclosed <flex> (missing </flex>)', $openTok->line);
                break;
            }

            if ($tok->type === TOK_TAG_CLOSE) {
                $closeName = $this->getTagName($tok->content);
                if ($closeName === 'flex') {
                    $this->advance(); // consume </flex>
                    break;
                }
                $this->error("Unexpected closing tag </$closeName> inside <flex>", $tok->line);
                $this->advance();
                continue;
            }

            if ($tok->type === TOK_TAG_OPEN || $tok->type === TOK_TAG_SELF) {
                $child = $this->parseElement();
                if ($child !== null) {
                    $node->children[] = $child;
                }
                continue;
            }

            // Skip comments, text, etc.
            $this->advance();
        }

        return $node;
    }

    /**
     * v6 M4: Parse <textbox> input element
     *
     * Syntax:
     *   <textbox x="10" y="10" w="200" h="30"
     *           v-model="searchText"
     *           placeholder="Search..."
     *           @keyup="onSearchKeyup"
     *           @enter="onSearch" />
     */
    private function parseTextBox(Token $tok): TextBoxNode
    {
        $attrs = $this->parseAttrs($tok->content);
        $this->advance();

        $x = (int)($attrs['x'] ?? 0);
        $y = (int)($attrs['y'] ?? 0);
        $w = (int)($attrs['w'] ?? 200);
        $h = (int)($attrs['h'] ?? 30);
        $vModel = $attrs['v-model'] ?? '';
        $placeholder = $attrs['placeholder'] ?? '';
        $class = $attrs['class'] ?? 'textbox';
        $align = $attrs['align'] ?? 'left';

        // Parse event handlers
        $keyHandler = '';
        $enterHandler = '';
        if (isset($attrs['@keyup'])) {
            $keyHandler = $attrs['@keyup'];
        }
        if (isset($attrs['@enter'])) {
            $enterHandler = $attrs['@enter'];
        } elseif (isset($attrs['@keydown'])) {
            // Map @keydown to general key handler
            $keyHandler = $attrs['@keydown'];
        }

        return new TextBoxNode(
            $x, $y, $w, $h,
            $vModel,
            $placeholder,
            $class,
            $align,
            $keyHandler,
            $enterHandler,
            $tok->line
        );
    }

    /**
     * v6 M5: Parse <list-item> element for v-for list rendering
     *
     * Syntax:
     *   <list-item items="itemsExpr"
     *              :item-height="40"
     *              class="item"
     *              :text-bind="item.text"
     *              @click="onItemClick"
     *              :click-arg="item.id" />
     */
    private function parseListItem(Token $tok): ListItemNode
    {
        $attrs = $this->parseAttrs($tok->content);
        $this->advance();

        // Support both "items" and ":items" syntax
        $x = (int)($attrs['x'] ?? 10);
        $y = (int)($attrs['y'] ?? 50);
        $w = (int)($attrs['w'] ?? 380);
        $itemsExpr = $attrs['items'] ?? $attrs[':items'] ?? '';
        $itemHeight = (int)($attrs['item-height'] ?? $attrs[':item-height'] ?? 40);
        $class = $attrs['class'] ?? 'list-item';
        $textBind = $attrs['text-bind'] ?? $attrs[':text-bind'] ?? '';
        $clickHandler = $attrs['@click'] ?? '';
        $clickArg = $attrs['click-arg'] ?? $attrs[':click-arg'] ?? '';

        if ($itemsExpr === '') {
            $this->error('<list-item> missing required :items attribute', $tok->line);
        }

        return new ListItemNode(
            $x, $y, $w,
            $itemsExpr,
            $itemHeight,
            $class,
            $textBind,
            $clickHandler,
            $clickArg,
            $tok->line
        );
    }

    /**
     * v6 M5: Parse <scroll-container> element
     *
     * Syntax:
     *   <scroll-container x="10" y="50" w="380" h="400" :scroll-top="scrollTop">
     *     <list-item ... />
     *   </scroll-container>
     */
    private function parseScrollContainer(Token $openTok): ScrollContainerNode
    {
        $attrs = $this->parseAttrs($openTok->content);
        $this->advance(); // consume opening tag

        $x = (int)($attrs['x'] ?? 0);
        $y = (int)($attrs['y'] ?? 0);
        $w = (int)($attrs['w'] ?? 0);
        $h = (int)($attrs['h'] ?? 0);
        $scrollTopBind = $attrs[':scroll-top'] ?? $attrs['scroll-top'] ?? '';

        if ($w === 0 || $h === 0) {
            $this->error("<scroll-container> has zero width or height", $openTok->line);
        }

        $node = new ScrollContainerNode($x, $y, $w, $h, $scrollTopBind, $openTok->line);

        // Parse children until </scroll-container>
        while (true) {
            $tok = $this->current();

            if ($tok->type === TOK_EOF) {
                $this->error('Unclosed <scroll-container> (missing </scroll-container>)', $openTok->line);
                break;
            }

            if ($tok->type === TOK_TAG_CLOSE) {
                $closeName = $this->getTagName($tok->content);
                if ($closeName === 'scroll-container') {
                    $this->advance(); // consume </scroll-container>
                    break;
                }
                $this->error("Unexpected closing tag </$closeName> inside <scroll-container>", $tok->line);
                $this->advance();
                continue;
            }

            if ($tok->type === TOK_TAG_OPEN || $tok->type === TOK_TAG_SELF) {
                $child = $this->parseElement();
                if ($child !== null) {
                    $node->children[] = $child;
                }
                continue;
            }

            // Skip comments, text, etc.
            $this->advance();
        }

        // Calculate total content height from children
        $contentHeight = 0;
        foreach ($node->children as $child) {
            if ($child instanceof ListItemNode) {
                $contentHeight += $child->itemHeight;
            }
        }
        $node->contentHeight = $contentHeight;

        return $node;
    }

    private function parseBtn(Token $tok): BtnNode
    {
        $attrs = $this->parseAttrs($tok->content);
        $this->advance();

        $row   = (int)($attrs['row'] ?? 0);
        $col   = (int)($attrs['col'] ?? 0);
        $label = $attrs['label'] ?? '';
        $cls   = $attrs['class'] ?? '';
        $click = $attrs['@click'] ?? '';
        $vIf   = $attrs['v-if'] ?? '';   // v4 M2.5

        // Parse @click: "method" or "method('arg')"
        $handler = $click;
        $arg     = null;
        if ($click !== '' && preg_match("/^(\w+)\(['\"]([^'\"]*)['\"]\)$/", $click, $m)) {
            $handler = $m[1];
            $arg     = $m[2];
        }

        if ($label === '') {
            $this->error('<btn> missing label', $tok->line);
        }
        if ($click === '') {
            $this->error('<btn> missing @click handler', $tok->line);
        }

        $node = new BtnNode($row, $col, $label, $cls, $handler, $arg, $tok->line);
        $node->vIf = $vIf;
        return $node;
    }

    // v5 M2: Parse a component reference tag
    // e.g., <display-panel x="0" y="80" :value="display" />
    // or    <display-panel x="0" y="80">...slot content...</display-panel>
    private function parseComponentRef(Token $tok, string $tagName, string $compFile): ComponentRefNode
    {
        $attrs = $this->parseAttrs($tok->content);
        $slotChildren = [];
        $selfClosing = ($tok->type === TOK_TAG_SELF);

        if (!$selfClosing) {
            $this->advance(); // consume opening tag <display-panel>

            // Parse any child elements between open and close tags (slot content)
            while (true) {
                $innerTok = $this->current();

                if ($innerTok->type === TOK_EOF) {
                    $this->error("Unclosed component <$tagName> (missing </$tagName>)", $tok->line);
                    break;
                }

                if ($innerTok->type === TOK_TAG_CLOSE) {
                    $closeName = $this->getTagName($innerTok->content);
                    if ($closeName === $tagName) {
                        $this->advance(); // consume </display-panel>
                        break;
                    }
                    $this->error("Unexpected closing tag </$closeName> inside <$tagName>", $innerTok->line);
                    $this->advance();
                    continue;
                }

                if ($innerTok->type === TOK_TAG_OPEN || $innerTok->type === TOK_TAG_SELF) {
                    $child = $this->parseElement();
                    if ($child !== null) {
                        $slotChildren[] = $child;
                    }
                    continue;
                }

                // Skip comments, text, etc.
                $this->advance();
            }
        } else {
            $this->advance(); // consume self-closing tag
        }

        $vIf = $attrs['v-if'] ?? '';

        $node = new ComponentRefNode($tagName, $compFile, $attrs, $slotChildren, $selfClosing, $tok->line);
        $node->vIf = $vIf;
        $node->isOverlay = isset($attrs['overlay']);
        return $node;
    }

    // ============================================================
    // AST → Layout Arrays (Lowering / Code Generation Prep)
    // ============================================================

    /**
     * Convert an AppNode AST to the layout arrays expected by the code generator.
     * This replaces the old direct regex→array approach.
     * 
     * @param AppNode $app         Parsed AST
     * @param array   $classStyles CSS class style map (from CssMappings::parseStyleBlock)
     * @return array  ['elements' => [...], 'buttons' => [...]]
     */
    public function lowerToLayout(AppNode $app, array $classStyles): array
    {
        $elements = [];
        $buttons  = [];
        $bindKeys = [];    // v4 M2.2: collect unique :bind keys for getBindValue generation
        $textBindKeys = []; // v6 M5: collect :bind values from <text> elements for getBindValue
        $handlerMap = [];  // v4 M2.3: handler => hasArg, for auto dispatchClick generation
        $condProps = [];   // v4 M2.5: collect property names from v-if conditions for evalCondition generation

        foreach ($app->children as $child) {
            if ($child instanceof RectNode) {
                $style = $classStyles[$child->class] ?? [];

                // v6 M5: Clickable rect -> convert to button
                if ($child->clickHandler !== '') {
                    $bg = $style['bg'] ?? 0x4488CC;
                    $fg = $style['fg'] ?? 0xFFFFFF;
                    $border = $this->calcBorderColor($bg);

                    // Parse handler with optional argument
                    $handler = $child->clickHandler;
                    $arg = null;
                    if (preg_match("/^(\w+)\(['\"]([^'\"]*)['\"]\)$/", $child->clickHandler, $m)) {
                        $handler = $m[1];
                        $arg = $m[2];
                    }

                    $buttons[] = [
                        'label'  => '',
                        'x'      => $child->x,
                        'y'      => $child->y,
                        'w'      => $child->w,
                        'h'      => $child->h,
                        'bg'     => $bg,
                        'fg'     => $fg,
                        'border' => $border,
                        'handler' => $handler,
                        'arg'    => $arg,
                        'layer'  => $child->layer,
                        'group_id' => $child->groupId,
                    ];

                    // Collect handler
                    if (!isset($handlerMap[$handler])) {
                        $handlerMap[$handler] = ($arg !== null);
                    } elseif ($arg !== null) {
                        $handlerMap[$handler] = true;
                    }
                } else {
                    $el = [
                        'type'  => 'rect',
                        'x'     => $child->x,
                        'y'     => $child->y,
                        'w'     => $child->w,
                        'h'     => $child->h,
                        'color' => $style['bg'] ?? 0,
                        'layer' => $child->layer,
                        'group_id' => $child->groupId,
                        'flex_index' => -1,  // v6 M5: background rects render first
                    ];
                    // v4 M2.5: v-if condition
                    if ($child->vIf !== '') {
                        $el['condition'] = $this->parseVIfCondition($child->vIf);
                        $condProps[$el['condition']['prop']] = true;
                    }
                    $elements[] = $el;
                }
            } elseif ($child instanceof TextNode) {
                $style = $classStyles[$child->class] ?? [];
                $el = [
                    'type'     => 'text',
                    'bind'     => $child->bind,
                    'x'        => $child->x,
                    'y'        => $child->y,
                    'align'    => $child->align,
                    'fontSize' => $style['fontSize'] ?? 16,
                    'color'    => $style['fg'] ?? 0xFFFFFF,
                    'bold'     => $style['bold'] ?? 0,
                    'layer'    => $child->layer,
                    'group_id' => $child->groupId,
                    'flex_index' => 0,  // v6 M5: text renders after backgrounds
                ];
                if ($child->hasContainer) {
                    $el['containerW'] = $child->containerW;
                    $el['containerX'] = $child->containerX;
                }
                // v4 M2.5: v-if condition
                if ($child->vIf !== '') {
                    $el['condition'] = $this->parseVIfCondition($child->vIf);
                    $condProps[$el['condition']['prop']] = true;
                }
                $elements[] = $el;
                // v6 M5: Collect :bind values from <text> elements for getBindValue
                if ($child->bind !== '') {
                    $bindKeys[$child->bind] = true;
                    $textBindKeys[$child->bind] = true;
                }
            } elseif ($child instanceof GridNode) {
                // Grid's buttons are computed at compile-time
                $gx = $child->x;
                $gy = $child->y;
                foreach ($child->buttons as $btn) {
                    $style  = $classStyles[$btn->class] ?? [];
                    $bg     = $style['bg'] ?? 0x323232;
                    $fg     = $style['fg'] ?? 0xFFFFFF;
                    $border = $style['border'] ?? CssMappings::borderColor($bg);

                    // Compile-time coordinate calculation
                    $bx = $gx + $btn->col * $child->cellW + $child->margin;
                    $by = $gy + $btn->row * $child->cellH + $child->margin;
                    $bw = $child->cellW - $child->margin * 2;
                    $bh = $child->cellH - $child->margin * 2;

                    $btnData = [
                        'label'   => $btn->label,
                        'x'       => $bx,
                        'y'       => $by,
                        'w'       => $bw,
                        'h'       => $bh,
                        'bg'      => $bg,
                        'fg'      => $fg,
                        'border'  => $border,
                        'handler' => $btn->handler,
                        'arg'     => $btn->arg,
                        'layer'   => $child->layer,
                        'group_id' => $child->groupId,
                    ];
                    // v4 M2.5: v-if condition on button (or propagated from grid)
                    if ($btn->vIf !== '') {
                        $btnData['condition'] = $this->parseVIfCondition($btn->vIf);
                        $condProps[$btnData['condition']['prop']] = true;
                    } elseif ($child->vIf !== '') {
                        // v5 M3: propagate grid's v-if to all buttons within it
                        $btnData['condition'] = $this->parseVIfCondition($child->vIf);
                        $condProps[$btnData['condition']['prop']] = true;
                    }
                    $buttons[] = $btnData;
                    // v4 M2.3: collect handler info for auto dispatchClick generation
                    if (!isset($handlerMap[$btn->handler])) {
                        $handlerMap[$btn->handler] = ($btn->arg !== null);
                    } elseif ($btn->arg !== null) {
                        $handlerMap[$btn->handler] = true;
                    }
                }
            } elseif ($child instanceof UnknownNode) {
                // Unknown tags are skipped in layout output (already reported as error)
                // But we add a placeholder comment in the generated file
                $elements[] = [
                    'type'   => '__unknown__',
                    'tag'    => $child->tagName,
                    'line'   => $child->line,
                ];
            } elseif ($child instanceof ComponentRefNode) {
                // v5 M2: Should have been resolved by sfc-compiler before lowering.
                // If we get here, the component was not resolved — report as error marker.
                $elements[] = [
                    'type'   => '__unresolved_component__',
                    'tag'    => $child->tagName,
                    'file'   => $child->componentFile,
                    'line'   => $child->line,
                ];
            } elseif ($child instanceof ForNode) {
                // v6 M3: v-for support — generate buttons for each iteration
                $iterations = $this->expandForNode($child, $classStyles);
                foreach ($iterations as $iteration) {
                    // Merge iteration buttons into main buttons array
                    foreach ($iteration['buttons'] as $btn) {
                        $buttons[] = $btn;
                    }
                    // Merge iteration elements
                    foreach ($iteration['elements'] as $el) {
                        $elements[] = $el;
                    }
                }
            } elseif ($child instanceof FlexNode) {
                // v6 M4: Flex container — layout children and expand into elements
                $flexElements = $this->expandFlexNode($child, $classStyles, $textBindKeys);
                foreach ($flexElements as $el) {
                    $elements[] = $el;
                }
            } elseif ($child instanceof TextBoxNode) {
                // v6 M4: TextBox — collect v-model as bindKey for getBindValue
                $style = $classStyles[$child->class] ?? [];
                if ($child->vModel !== '') {
                    $bindKeys[$child->vModel] = true;
                }
                $elements[] = [
                    'type'     => 'textbox',
                    'bind'     => $child->vModel,
                    'placeholder' => $child->placeholder,  // v6 M5: pass placeholder
                    'x'        => $child->x,
                    'y'        => $child->y,
                    'w'        => $child->w,
                    'h'        => $child->h,
                    'align'    => $child->align,
                    'fontSize' => $style['fontSize'] ?? 16,
                    'color'    => $style['fg'] ?? 0xFFFFFF,
                    'bg'       => $style['bg'] ?? 0x1E1E1E,
                    'cursor'   => true,
                    'layer'    => $child->layer,
                    'group_id' => $child->groupId,
                    'flex_index' => 0,  // v6 M5: textbox renders after backgrounds
                ];
                // Collect keyboard event handlers
                if ($child->keyHandler !== '') {
                    $handlerMap[$child->keyHandler] = true;
                }
                if ($child->enterHandler !== '') {
                    $handlerMap[$child->enterHandler] = true;
                }
            } elseif ($child instanceof ListItemNode) {
                // v6 M5: List item — expand into multiple list item elements based on items data
                // v6 M5 FIX: Add :items expression as bindKey for getBindValue (e.g. "todoItems")
                if ($child->itemsExpr !== '') {
                    $bindKeys[$child->itemsExpr] = true;
                }
                $listElements = $this->expandListItemNode($child, $classStyles, $bindKeys, $handlerMap, $condProps);
                foreach ($listElements['elements'] as $el) {
                    $elements[] = $el;
                }
                foreach ($listElements['buttons'] as $btn) {
                    $buttons[] = $btn;
                }
                // Merge collected bindKeys and handlers back
                foreach ($listElements['collectedBindKeys'] as $key) {
                    $bindKeys[$key] = true;
                }
                foreach ($listElements['collectedHandlers'] as $handler => $hasArg) {
                    if (!isset($handlerMap[$handler])) {
                        $handlerMap[$handler] = $hasArg;
                    } elseif ($hasArg) {
                        $handlerMap[$handler] = true;
                    }
                }
                foreach ($listElements['collectedCondProps'] as $prop) {
                    $condProps[$prop] = true;
                }
            } elseif ($child instanceof ScrollContainerNode) {
                // v6 M5: ScrollContainer — draw container rect + scrollbar, process children
                $style = $classStyles['scroll-container'] ?? [];
                $containerBg = $style['bg'] ?? 0x2D2D2D;
                $scrollbarW = 12;
                $scrollbarBg = $style['scrollbar-bg'] ?? 0x4A4A4A;
                $scrollbarThumb = $style['scrollbar-thumb'] ?? 0x888888;

                // v6 M5 FIX: Calculate actual content height from expanded children FIRST
                $actualContentHeight = 0;
                $scrollChildrenElements = [];
                $scrollChildrenButtons = [];

                foreach ($child->children as $listChild) {
                    if ($listChild instanceof ListItemNode) {
                        // v6 M5 FIX: Add :items expression as bindKey for getBindValue
                        if ($listChild->itemsExpr !== '') {
                            $bindKeys[$listChild->itemsExpr] = true;
                        }
                        $listElements = $this->expandListItemNode($listChild, $classStyles, $bindKeys, $handlerMap, $condProps);
                        // Use maxItems * itemHeight for correct content height
                        $actualContentHeight = $listElements['maxItems'] * $listChild->itemHeight;

                        foreach ($listElements['elements'] as $el) {
                            $el['scroll-container'] = true; // Mark as scrollable
                            $scrollChildrenElements[] = $el;
                        }
                        foreach ($listElements['buttons'] as $btn) {
                            $btn['scroll-container'] = true;
                            $scrollChildrenButtons[] = $btn;
                        }
                        // v6 M5 FIX: Merge collected bindKeys from list expansion
                        foreach ($listElements['collectedBindKeys'] as $key) {
                            $bindKeys[$key] = true;
                        }
                        // v6 M5 FIX: Merge collected handlers (was missing, caused deleteItem dispatchClick not generated)
                        foreach ($listElements['collectedHandlers'] as $handler => $hasArg) {
                            if (!isset($handlerMap[$handler])) {
                                $handlerMap[$handler] = $hasArg;
                            } elseif ($hasArg) {
                                $handlerMap[$handler] = true;
                            }
                        }
                        foreach ($listElements['collectedCondProps'] as $prop) {
                            $condProps[$prop] = true;
                        }
                    }
                }

                // Container rect with CORRECT content-height
                $elements[] = [
                    'type' => 'scroll-container',
                    'x' => $child->x,
                    'y' => $child->y,
                    'w' => $child->w,
                    'h' => $child->h,
                    'bg' => $containerBg,
                    'scrollbar-w' => $scrollbarW,
                    'scrollbar-bg' => $scrollbarBg,
                    'scrollbar-thumb' => $scrollbarThumb,
                    'scroll-top-bind' => $child->scrollTopBind,
                    'content-height' => $actualContentHeight > 0 ? $actualContentHeight : $child->contentHeight,
                    'layer' => $child->layer,
                    'group_id' => $child->groupId,
                ];

                // Collect scroll-top bind
                if ($child->scrollTopBind !== '') {
                    $bindKeys[$child->scrollTopBind] = true;
                }

                // Append expanded children elements and buttons
                foreach ($scrollChildrenElements as $el) {
                    $elements[] = $el;
                }
                foreach ($scrollChildrenButtons as $btn) {
                    $buttons[] = $btn;
                }
            }
        }

        return [
            'elements'    => $elements,
            'buttons'     => $buttons,
            'bindKeys'    => array_keys($bindKeys),     // v4 M2.2: for auto getBindValue generation
            'handlerMap'  => $handlerMap,               // v4 M2.3: for auto dispatchClick generation
            'condProps'   => array_keys($condProps),    // v4 M2.5: for auto evalCondition generation
            'textBindKeys' => array_keys($textBindKeys), // v6 M5: :bind values from <text> elements
        ];
    }

    // ============================================================
    // Helpers
    // ============================================================

    /**
     * Extract tag name from "<tagname ...>" or "</tagname>" or "<tagname ... />"
     */
    private function getTagName(string $tagText): string
    {
        $tagText = trim($tagText, "<> \t\n\r\0\x0B/");
        $spacePos = strpos($tagText, ' ');
        if ($spacePos !== false) {
            return substr($tagText, 0, $spacePos);
        }
        // Handle self-closing: "tagname/" → "tagname"
        if (substr($tagText, -1) === '/') {
            return rtrim(substr($tagText, 0, -1));
        }
        return $tagText;
    }

    /**
     * Parse attributes from a tag string like: key="value" key2="value2"
     */
    private function parseAttrs(string $tagText): array
    {
        $attrs = [];
        // Extract everything after the tag name
        $tagName = $this->getTagName($tagText);
        $attrStr = substr($tagText, strlen($tagName) + 1); // skip "<tagname "
        $attrStr = trim($attrStr, "> \t\n\r\0\x0B/");

        if ($attrStr === '') {
            return $attrs;
        }

        // Match attr="value" or attr='value' — supports :bind, @click, container-w, etc.
        if (preg_match_all('#([a-zA-Z@:-][a-zA-Z0-9@:_-]*)(?:\s*=\s*"([^"]*)"|\s*=\s*\'([^\']*)\')?#', $attrStr, $m, PREG_SET_ORDER)) {
            foreach ($m as $a) {
                $key   = $a[1];
                $value = $a[2] ?? ($a[3] ?? '');
                $attrs[$key] = $value;
            }
        }

        return $attrs;
    }

    private function current(): Token
    {
        return $this->tokens[$this->pos] ?? new Token(TOK_EOF, '', 0);
    }

    private function advance(): void
    {
        if ($this->pos < count($this->tokens)) {
            $this->pos++;
        }
    }

    private function skipUntilTag(): void
    {
        while ($this->current()->type === TOK_TEXT || $this->current()->type === TOK_COMMENT) {
            $this->advance();
        }
    }

    /**
     * v4 M2.5: Parse v-if condition string into a structured condition array.
     * v5 M3: Added !propName negation support.
     * 
     * Supported forms:
     *   "propName"            → ['prop' => 'propName', 'op' => 'truthy']
     *   "!propName"           → ['prop' => 'propName', 'op' => 'falsy']
     *   "propName == 'val'"   → ['prop' => 'propName', 'op' => '==', 'value' => 'val']
     *   "propName != 'val'"   → ['prop' => 'propName', 'op' => '!=', 'value' => 'val']
     */
    private function parseVIfCondition(string $vIf): array
    {
        // Equality/inequality comparison
        if (preg_match("/^(\w+)\s*(==|!=)\s*'([^']*)'$/", $vIf, $m)) {
            return ['prop' => $m[1], 'op' => $m[2], 'value' => $m[3]];
        }
        // Negation check: !propName
        if (preg_match('/^!(\w+)$/', $vIf, $m)) {
            return ['prop' => $m[1], 'op' => 'falsy'];
        }
        // Simple truthy check (non-empty property)
        if (preg_match('/^(\w+)$/', $vIf)) {
            return ['prop' => $vIf, 'op' => 'truthy'];
        }
        // Fallback: treat as truthy (best-effort)
        return ['prop' => $vIf, 'op' => 'truthy'];
    }

    private function error(string $message, int $line): void
    {
        $this->errors[] = new TemplateParseError($message, $line);
    }

    // ============================================================
    // AST Debug: convert to array for JSON dump
    // ============================================================

    private function astToArray(TemplateNode $node): array
    {
        $type = get_class($node);
        $result = ['_type' => $type, '_line' => $node->line];

        switch ($type) {
            case 'AppNode':
                $result['title']  = $node->title;
                $result['width']  = $node->width;
                $result['height'] = $node->height;
                $result['children'] = array_map([$this, 'astToArray'], $node->children);
                break;

            case 'RectNode':
                $result['x'] = $node->x;
                $result['y'] = $node->y;
                $result['w'] = $node->w;
                $result['h'] = $node->h;
                $result['class'] = $node->class;
                if ($node->vIf !== '') $result['vIf'] = $node->vIf;
                break;

            case 'TextNode':
                $result['x'] = $node->x;
                $result['y'] = $node->y;
                $result['bind'] = $node->bind;
                $result['class'] = $node->class;
                $result['align'] = $node->align;
                if ($node->vModel !== '') $result['vModel'] = $node->vModel;
                if ($node->vIf !== '') $result['vIf'] = $node->vIf;
                if ($node->hasContainer) {
                    $result['containerW'] = $node->containerW;
                    $result['containerX'] = $node->containerX;
                }
                break;

            case 'GridNode':
                $result['x'] = $node->x;
                $result['y'] = $node->y;
                $result['cols'] = $node->cols;
                $result['rows'] = $node->rows;
                $result['cellW'] = $node->cellW;
                $result['cellH'] = $node->cellH;
                $result['margin'] = $node->margin;
                if ($node->vIf !== '') $result['vIf'] = $node->vIf;
                $result['buttons'] = array_map([$this, 'astToArray'], $node->buttons);
                break;

            case 'BtnNode':
                $result['row'] = $node->row;
                $result['col'] = $node->col;
                $result['label'] = $node->label;
                $result['class'] = $node->class;
                $result['handler'] = $node->handler;
                $result['arg'] = $node->arg;
                if ($node->vIf !== '') $result['vIf'] = $node->vIf;
                break;

            case 'UnknownNode':
                $result['tagName'] = $node->tagName;
                break;

            case 'ComponentRefNode':
                $result['tagName'] = $node->tagName;
                $result['componentFile'] = $node->componentFile;
                $result['props'] = $node->props;
                $result['selfClosing'] = $node->selfClosing;
                if (count($node->slotChildren) > 0) {
                    $result['slotChildren'] = array_map([$this, 'astToArray'], $node->slotChildren);
                }
                if ($node->vIf !== '') $result['vIf'] = $node->vIf;
                break;

            case 'ForNode':
                $result['itemVar'] = $node->itemVar;
                $result['sourceExpr'] = $node->sourceExpr;
                $result['keyExpr'] = $node->keyExpr;
                $result['children'] = array_map([$this, 'astToArray'], $node->children);
                break;
        }

        return $result;
    }

    // ============================================================
    // v6 M3: v-for expansion
    // ============================================================

    /**
     * Expand a ForNode into multiple iteration instances.
     * For compile-time expansion, we use static data from the script.
     *
     * @param ForNode $forNode
     * @param array $classStyles
     * @return array Array of iteration results, each containing 'buttons' and 'elements'
     */
    /**
     * v6 M4: Expand FlexNode into flat elements array
     *
     * Uses FlexLayout engine to compute child positions at compile time.
     *
     * @param array &$textBindKeys OUT: collects :bind values from TextNode children
     */
    private function expandFlexNode(FlexNode $flex, array $classStyles, array &$textBindKeys): array
    {
        // v6 M5: Collect :bind values from FlexNode's TextNode children
        foreach ($flex->children as $child) {
            if ($child instanceof TextNode && $child->bind !== '') {
                $textBindKeys[$child->bind] = true;
            }
        }

        // Collect child dimensions for flex calculation
        $children = [];
        foreach ($flex->children as $child) {
            $dims = $this->getChildDimensions($child, $classStyles);
            if ($dims !== null) {
                $children[] = $dims;
            }
        }

        if (count($children) === 0) {
            return [];
        }

        // Use FlexLayout engine
        $flexEngine = new FlexLayout(
            $flex->x, $flex->y, $flex->w, $flex->h,
            $flex->direction, $flex->gap,
            $flex->justify, $flex->align, $flex->wrap
        );

        $positions = $flexEngine->layout($children);

        // Build flat elements array with computed positions
        $elements = [];

        // v6 M5 FIX: Add flex container background rect
        $flexStyle = $classStyles[$flex->class] ?? [];
        if (isset($flexStyle['bg'])) {
            $elements[] = [
                'type'  => 'rect',
                'x'     => $flex->x,
                'y'     => $flex->y,
                'w'     => $flex->w,
                'h'     => $flex->h,
                'color' => $flexStyle['bg'],
                'layer' => 0,
                'group_id' => 'app',
                'flex_index' => -1,  // background, no flex layout
            ];
        }

        for ($i = 0; $i < count($flex->children); $i++) {
            $child = $flex->children[$i];
            $pos = $positions[$i];

            if ($child instanceof RectNode) {
                $style = $classStyles[$child->class] ?? [];
                $elements[] = [
                    'type'  => 'rect',
                    'x'     => $pos['x'],
                    'y'     => $pos['y'],
                    'w'     => $pos['w'],
                    'h'     => $pos['h'],
                    'color' => $style['bg'] ?? 0,
                    'layer' => $child->layer,
                    'group_id' => $child->groupId,
                    'flex_index' => $i,
                ];
            } elseif ($child instanceof TextNode) {
                $style = $classStyles[$child->class] ?? [];
                $elements[] = [
                    'type'     => 'text',
                    'bind'     => $child->bind,
                    'x'        => $pos['x'],
                    'y'        => $pos['y'],
                    'w'        => $pos['w'],
                    'h'        => $pos['h'],
                    'align'    => $child->align,
                    'fontSize' => $style['fontSize'] ?? 16,
                    'color'    => $style['fg'] ?? 0xFFFFFF,
                    'bold'     => $style['bold'] ?? 0,
                    'layer'    => $child->layer,
                    'group_id' => $child->groupId,
                    'flex_index' => $i,
                ];
            } elseif ($child instanceof GridNode) {
                // Expand grid children within flex container
                $gx = $pos['x'];
                $gy = $pos['y'];
                foreach ($child->buttons as $btn) {
                    $style  = $classStyles[$btn->class] ?? [];
                    $bg     = $style['bg'] ?? 0x323232;
                    $fg     = $style['fg'] ?? 0xFFFFFF;
                    $border = $style['border'] ?? CssMappings::borderColor($bg);

                    $bx = $gx + $btn->col * $child->cellW + $child->margin;
                    $by = $gy + $btn->row * $child->cellH + $child->margin;
                    $bw = $child->cellW - $child->margin * 2;
                    $bh = $child->cellH - $child->margin * 2;

                    $elements[] = [
                        'type'   => 'button',
                        'label'  => $btn->label,
                        'x'      => $bx,
                        'y'      => $by,
                        'w'      => $bw,
                        'h'      => $bh,
                        'bg'     => $bg,
                        'fg'     => $fg,
                        'border' => $border,
                        'handler' => $btn->handler,
                        'arg'    => $btn->arg,
                        'layer'  => $child->layer,
                        'group_id' => $child->groupId,
                        'flex_index' => $i,
                    ];
                }
            }
            // ComponentRefNode, ForNode, UnknownNode: skip in flex expansion
        }

        return $elements;
    }

    /**
     * Get dimensions from a child node for flex calculation
     */
    private function getChildDimensions(TemplateNode $child, array $classStyles): ?array
    {
        if ($child instanceof RectNode) {
            return [
                'w' => $child->w,
                'h' => $child->h,
                'flex-grow' => 0,
                'flex-shrink' => 1.0,
                'flex-basis' => 0,
                'align-self' => 'stretch',
            ];
        } elseif ($child instanceof TextNode) {
            $style = $classStyles[$child->class] ?? [];
            $h = (int)($style['height'] ?? 30);
            return [
                'w' => (int)($style['width'] ?? 100),
                'h' => $h,
                'flex-grow' => 0,
                'flex-shrink' => 1.0,
                'flex-basis' => 0,
                'align-self' => 'stretch',
            ];
        } elseif ($child instanceof GridNode) {
            $w = $child->cols * $child->cellW;
            $h = $child->rows * $child->cellH;
            return [
                'w' => $w,
                'h' => $h,
                'flex-grow' => 0,
                'flex-shrink' => 1.0,
                'flex-basis' => 0,
                'align-self' => 'stretch',
            ];
        }
        return null;
    }

    // ============================================================
    // v6 M5: List item expansion
    // ============================================================

    /**
     * v6 M5: Expand a ListItemNode into multiple list item elements based on items data.
     *
     * For compile-time expansion, we generate elements for a fixed number of list items
     * and use runtime data binding for the actual content.
     *
     * @param ListItemNode $listItem
     * @param array $classStyles
     * @param array &$bindKeys (modified in place)
     * @param array &$handlerMap (modified in place)
     * @param array &$condProps (modified in place)
     * @return array
     */
    private function expandListItemNode(
        ListItemNode $listItem,
        array $classStyles,
        array &$bindKeys,
        array &$handlerMap,
        array &$condProps
    ): array {
        $elements = [];
        $buttons = [];
        $collectedBindKeys = [];
        $collectedHandlers = [];
        $collectedCondProps = [];

        $style = $classStyles[$listItem->class] ?? [];
        $bg = $style['bg'] ?? 0x3E3E3E;
        $fg = $style['fg'] ?? 0xDDDDDD;
        $fontSize = $style['fontSize'] ?? 14;
        $borderColor = $style['border'] ?? $this->calcBorderColor($bg);

        // Static expansion: generate 20 list item slots (max visible items)
        $maxItems = 20;
        $containerX = $listItem->x;
        $containerY = $listItem->y;
        $containerW = $listItem->w > 0 ? $listItem->w : ($style['width'] ?? 380);
        $itemHeight = $listItem->itemHeight;

        // Collect text bind expression for dynamic content
        $textBind = $listItem->textBind;
        if ($textBind !== '') {
            // Extract the property part from binding like "item.text" -> "item_text_0", "item_text_1", etc.
            $bindVar = preg_replace('/[^a-zA-Z0-9_]/', '_', $textBind);
            for ($i = 0; $i < $maxItems; $i++) {
                $bindKey = "{$bindVar}_{$i}";
                $collectedBindKeys[$bindKey] = true;
            }
        }

        // Collect click handler
        $clickHandler = $listItem->clickHandler;
        if ($clickHandler !== '') {
            $collectedHandlers[$clickHandler] = true; // click-arg is optional
        }

        // Generate static list item elements
        for ($i = 0; $i < $maxItems; $i++) {
            $itemY = $containerY + $i * $itemHeight;

            // Background rect for list item
            $elements[] = [
                'type' => 'rect',
                'x' => $containerX,
                'y' => $itemY,
                'w' => $containerW,
                'h' => $itemHeight - 2, // gap between items
                'color' => $bg,
                'layer' => $listItem->layer,
                'group_id' => $listItem->groupId,
                'list_index' => $i,  // v6 M5: for runtime item binding
            ];

            // Text element for list item text
            if ($textBind !== '') {
                $bindKey = "{$bindVar}_{$i}";
                $elements[] = [
                    'type' => 'text',
                    'bind' => $bindKey,
                    'x' => $containerX + 10,
                    'y' => $itemY + ($itemHeight - $fontSize) / 2,
                    'w' => $containerW - 60, // leave space for delete button
                    'h' => $fontSize + 4,
                    'align' => 'left',
                    'fontSize' => $fontSize,
                    'color' => $fg,
                    'bold' => 0,
                    'layer' => $listItem->layer,
                    'group_id' => $listItem->groupId,
                    'list_index' => $i,
                ];
            }

            // Delete button for each list item
            $btnX = $containerX + $containerW - 50;
            $btnY = $itemY + 4;
            $btnW = 40;
            $btnH = $itemHeight - 8;
            $buttons[] = [
                'label' => 'X',
                'x' => $btnX,
                'y' => $btnY,
                'w' => $btnW,
                'h' => $btnH,
                'bg' => 0xCC3333,
                'fg' => 0xFFFFFF,
                'border' => $this->calcBorderColor(0xCC3333),
                'handler' => $clickHandler,
                'arg' => (string)$i,  // Pass index for runtime lookup
                'layer' => $listItem->layer,
                'group_id' => $listItem->groupId,
                'list_index' => $i,  // v6 M5: for runtime item lookup
            ];
        }

        return [
            'elements' => $elements,
            'buttons' => $buttons,
            'collectedBindKeys' => array_keys($collectedBindKeys),
            'collectedHandlers' => $collectedHandlers,
            'collectedCondProps' => array_keys($collectedCondProps),
            'maxItems' => $maxItems,
            'bindVar' => $bindVar ?? '',
        ];
    }

    /**
     * Calculate border color based on background color (inlined CssMappings::borderColor logic)
     */
    private function calcBorderColor(int $bg): int
    {
        $r = min(255, (($bg >> 16) & 0xFF) + 20);
        $g = min(255, (($bg >> 8) & 0xFF) + 20);
        $b = min(255, ($bg & 0xFF) + 20);
        return ($r << 16) | ($g << 8) | $b;
    }

    private function expandForNode(ForNode $forNode, array $classStyles): array
    {
        // Static button data for numpad expansion
        $staticItems = $this->getStaticForItems($forNode->sourceExpr);

        $iterations = [];
        $index = 0;
        foreach ($staticItems as $item) {
            $iteration = [
                'buttons' => [],
                'elements' => [],
            ];

            foreach ($forNode->children as $child) {
                if ($child instanceof GridNode) {
                    $gx = $child->x;
                    $gy = $child->y;

                    foreach ($child->buttons as $btn) {
                        $style  = $classStyles[$btn->class] ?? [];
                        $bg     = $style['bg'] ?? 0x323232;
                        $fg     = $style['fg'] ?? 0xFFFFFF;
                        $border = $style['border'] ?? CssMappings::borderColor($bg);

                        // Replace {{item.label}} in label
                        $label = $btn->label;
                        if ($forNode->itemVar !== '' && isset($item['label'])) {
                            $label = str_replace('{{' . $forNode->itemVar . '.label}}', $item['label'], $label);
                            $label = str_replace('{{' . $forNode->itemVar . '.value}}', $item['value'] ?? '', $label);
                        }

                        $bx = $gx + $btn->col * $child->cellW + $child->margin;
                        $by = $gy + $btn->row * $child->cellH + $child->margin;
                        $bw = $child->cellW - $child->margin * 2;
                        $bh = $child->cellH - $child->margin * 2;

                        // Replace {{item.handler}} in handler
                        $handler = $btn->handler;
                        $arg = $btn->arg;
                        if ($forNode->itemVar !== '' && isset($item['handler'])) {
                            $handler = str_replace('{{' . $forNode->itemVar . '.handler}}', $item['handler'], $handler);
                            $arg = str_replace('{{' . $forNode->itemVar . '.value}}', $item['value'] ?? '', $arg);
                        }

                        $iteration['buttons'][] = [
                            'label'   => $label,
                            'x'       => $bx,
                            'y'       => $by,
                            'w'       => $bw,
                            'h'       => $bh,
                            'bg'      => $bg,
                            'fg'      => $fg,
                            'border'  => $border,
                            'handler' => $handler,
                            'arg'     => $arg,
                            'layer'   => $child->layer,
                            'group_id' => $child->groupId,
                            'key'     => $index,  // v6 M3: static index key for button
                        ];
                    }
                }
            }
            $iterations[] = $iteration;
            $index++;
        }

        return $iterations;
    }

    /**
     * Get static items for v-for expansion based on source expression.
     * This maps common source expressions to predefined button data.
     */
    private function getStaticForItems(string $sourceExpr): array
    {
        // Map source expressions to static item definitions
        $staticMaps = [
            'numButtons' => [
                ['label' => '7', 'value' => '7', 'handler' => 'handleButton', 'key' => 0],
                ['label' => '8', 'value' => '8', 'handler' => 'handleButton', 'key' => 1],
                ['label' => '9', 'value' => '9', 'handler' => 'handleButton', 'key' => 2],
                ['label' => '4', 'value' => '4', 'handler' => 'handleButton', 'key' => 3],
                ['label' => '5', 'value' => '5', 'handler' => 'handleButton', 'key' => 4],
                ['label' => '6', 'value' => '6', 'handler' => 'handleButton', 'key' => 5],
                ['label' => '1', 'value' => '1', 'handler' => 'handleButton', 'key' => 6],
                ['label' => '2', 'value' => '2', 'handler' => 'handleButton', 'key' => 7],
                ['label' => '3', 'value' => '3', 'handler' => 'handleButton', 'key' => 8],
                ['label' => '0', 'value' => '0', 'handler' => 'handleButton', 'key' => 9],
            ],
            'opButtons' => [
                ['label' => '/', 'value' => '/', 'handler' => 'handleButton', 'key' => 10],
                ['label' => '*', 'value' => '*', 'handler' => 'handleButton', 'key' => 11],
                ['label' => '-', 'value' => '-', 'handler' => 'handleButton', 'key' => 12],
                ['label' => '+', 'value' => '+', 'handler' => 'handleButton', 'key' => 13],
            ],
        ];

        return $staticMaps[$sourceExpr] ?? [];
    }
}
