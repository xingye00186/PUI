<?php
/**
 * AST Node classes for VueCalc SFC Template
 * 
 * Each node carries a line number for error reporting.
 * Used by the recursive descent parser (template-parser.php).
 */

abstract class TemplateNode
{
    /** Source file line number (1-based) */
    public int $line;

    /** v4 M2.5: v-if condition (empty string = no condition) */
    public string $vIf = '';

    /** v5 M3: overlay layer number (0 = base layer) */
    public int $layer = 0;

    /** v5 M4: component group identifier (default 'app' for top-level elements) */
    public string $groupId = 'app';

    public function __construct(int $line = 0)
    {
        $this->line = $line;
    }
}

class AppNode extends TemplateNode
{
    public string $title;
    public int $width;
    public int $height;

    /** @var TemplateNode[] */
    public array $children = [];

    public function __construct(string $title, int $width, int $height, int $line = 0)
    {
        parent::__construct($line);
        $this->title  = $title;
        $this->width  = $width;
        $this->height = $height;
    }
}

class RectNode extends TemplateNode
{
    public int $x;
    public int $y;
    public int $w;
    public int $h;
    public string $class;
    /** @deprecated v6: Use <btn> inside <grid> for clickable buttons */
    public string $clickHandler = '';

    public function __construct(int $x, int $y, int $w, int $h, string $class, int $line = 0)
    {
        parent::__construct($line);
        $this->x     = $x;
        $this->y     = $y;
        $this->w     = $w;
        $this->h     = $h;
        $this->class = $class;
    }
}

class TextNode extends TemplateNode
{
    public int $x;
    public int $y;
    public string $bind;       // :bind="prop"
    public string $vModel;     // v4 M2.4: v-model="prop" (mutually exclusive with :bind)
    public string $class;
    public string $align;      // left|right
    public int $containerW;
    public int $containerX;
    public bool $hasContainer;

    public function __construct(
        int $x, int $y, string $bind, string $class,
        string $align = 'left',
        int $containerW = 0, int $containerX = 0,
        int $line = 0,
        string $vModel = ''   // v4 M2.4
    ) {
        parent::__construct($line);
        $this->x           = $x;
        $this->y           = $y;
        $this->bind        = $bind;
        $this->vModel      = $vModel;
        $this->class       = $class;
        $this->align       = $align;
        $this->containerW  = $containerW;
        $this->containerX  = $containerX;
        $this->hasContainer = ($containerW > 0);
    }
}

/**
 * v6 M4: TextBox input element
 *
 * Supports:
 *   - v-model for two-way binding
 *   - placeholder text
 *   - keyboard events (@keyup, @keydown, @enter)
 *   - cursor position tracking
 */
class TextBoxNode extends TemplateNode
{
    public int $x;
    public int $y;
    public int $w;
    public int $h;
    public string $vModel;       // v-model property name
    public string $placeholder; // placeholder text
    public string $class;
    public string $align;
    public string $keyHandler;    // @keyup handler
    public string $enterHandler; // @enter handler

    public function __construct(
        int $x, int $y, int $w, int $h,
        string $vModel,
        string $placeholder = '',
        string $class = 'textbox',
        string $align = 'left',
        string $keyHandler = '',
        string $enterHandler = '',
        int $line = 0
    ) {
        parent::__construct($line);
        $this->x = $x;
        $this->y = $y;
        $this->w = $w;
        $this->h = $h;
        $this->vModel = $vModel;
        $this->placeholder = $placeholder;
        $this->class = $class;
        $this->align = $align;
        $this->keyHandler = $keyHandler;
        $this->enterHandler = $enterHandler;
    }
}

class GridNode extends TemplateNode
{
    public int $x;
    public int $y;
    public int $cols;
    public int $rows;
    public int $cellW;
    public int $cellH;
    public int $margin;

    /** @var BtnNode[] */
    public array $buttons = [];

    public function __construct(
        int $x, int $y,
        int $cols, int $rows, int $cellW, int $cellH, int $margin,
        int $line = 0
    ) {
        parent::__construct($line);
        $this->x      = $x;
        $this->y      = $y;
        $this->cols   = $cols;
        $this->rows   = $rows;
        $this->cellW  = $cellW;
        $this->cellH  = $cellH;
        $this->margin = $margin;
    }
}

class BtnNode extends TemplateNode
{
    public int $row;
    public int $col;
    public string $label;
    public string $class;
    public string $handler;   // @click handler method name
    public ?string $arg;      // @click argument (null if none)

    public function __construct(
        int $row, int $col, string $label, string $class,
        string $handler, ?string $arg = null,
        int $line = 0
    ) {
        parent::__construct($line);
        $this->row     = $row;
        $this->col     = $col;
        $this->label   = $label;
        $this->class   = $class;
        $this->handler = $handler;
        $this->arg     = $arg;
    }
}

/**
 * Represents an unknown or unsupported tag.
 * Parser collects these for error reporting rather than silently ignoring.
 */
class UnknownNode extends TemplateNode
{
    public string $tagName;

    public function __construct(string $tagName, int $line = 0)
    {
        parent::__construct($line);
        $this->tagName = $tagName;
    }
}

/**
 * v6 M3: Represents a v-for loop wrapper in the template.
 *
 * e.g., <template v-for="item in items" :key="item.id">
 *
 * Contains a source template that will be instantiated for each iteration.
 */
class ForNode extends TemplateNode
{
    /** Iterator variable name, e.g. 'item' */
    public string $itemVar;

    /** Source expression, e.g. 'items' */
    public string $sourceExpr;

    /** Dynamic :key attribute value, e.g. 'item.id' */
    public string $keyExpr;

    /** Child nodes to be repeated */
    /** @var TemplateNode[] */
    public array $children = [];

    /** For v-for loop: collect expanded copies here */
    /** @var TemplateNode[][] */
    public array $iterations = [];

    public function __construct(
        string $itemVar,
        string $sourceExpr,
        string $keyExpr,
        int $line = 0
    ) {
        parent::__construct($line);
        $this->itemVar = $itemVar;
        $this->sourceExpr = $sourceExpr;
        $this->keyExpr = $keyExpr;
    }
}

/**
 * v6 M4: Flex container node
 *
 * Represents a flex container that arranges children in a row or column.
 * The SFC compiler expands this at compile-time into absolute-positioned elements.
 *
 * Supports:
 *   - direction: row | column
 *   - gap: spacing between children
 *   - justify-content: flex-start | center | flex-end | space-between
 *   - align-items: stretch | flex-start | center | flex-end
 *   - flex-wrap: nowrap | wrap
 */
class FlexNode extends TemplateNode
{
    public int $x;
    public int $y;
    public int $w;
    public int $h;
    public string $direction;  // row | column
    public int $gap;           // spacing between children
    public string $justify;     // main-axis alignment
    public string $align;      // cross-axis alignment
    public string $wrap;       // nowrap | wrap
    public string $class = ''; // v6 M5: CSS class for styling

    /** @var TemplateNode[] */
    public array $children = [];

    public function __construct(
        int $x, int $y, int $w, int $h,
        string $direction = 'row',
        int $gap = 0,
        string $justify = 'flex-start',
        string $align = 'stretch',
        string $wrap = 'nowrap',
        int $line = 0
    ) {
        parent::__construct($line);
        $this->x = $x;
        $this->y = $y;
        $this->w = $w;
        $this->h = $h;
        $this->direction = $direction;
        $this->gap = $gap;
        $this->justify = $justify;
        $this->align = $align;
        $this->wrap = $wrap;
    }
}

/**
 * v6 M5: List item node for v-for rendering
 *
 * Represents a dynamic list item that renders based on array data.
 * Uses relative positioning within a list container.
 *
 * Syntax:
 *   <list-item :items="todoItems" :item-height="50" class="item" @click="onItemClick" />
 *
 * The SFC compiler expands this at compile-time into static element instances
 * based on the initial array data.
 */
class ListItemNode extends TemplateNode
{
    /** X position */
    public int $x;

    /** Y position */
    public int $y;

    /** Width */
    public int $w;

    /** Property name containing the array data */
    public string $itemsExpr;

    /** Height of each item in pixels */
    public int $itemHeight;

    /** CSS class for the item background */
    public string $class;

    /** Text bind key (for item text display) */
    public string $textBind;

    /** Click handler method name */
    public string $clickHandler;

    /** Click argument expression */
    public string $clickArg;

    public function __construct(
        int $x,
        int $y,
        int $w,
        string $itemsExpr,
        int $itemHeight,
        string $class,
        string $textBind,
        string $clickHandler,
        string $clickArg,
        int $line = 0
    ) {
        parent::__construct($line);
        $this->x = $x;
        $this->y = $y;
        $this->w = $w;
        $this->itemsExpr = $itemsExpr;
        $this->itemHeight = $itemHeight;
        $this->class = $class;
        $this->textBind = $textBind;
        $this->clickHandler = $clickHandler;
        $this->clickArg = $clickArg;
    }
}

/**
 * v5 M2: Represents a child component reference in the template.
 *
 * e.g., <display-panel x="0" y="80" :value="display" />
 *
 * The sfc-compiler resolves these at compile-time by recursively
 * compiling the referenced .vue file and inlining its layout elements.
 */
class ComponentRefNode extends TemplateNode
{
    /** Custom tag name, e.g. 'display-panel' */
    public string $tagName;

    /** Absolute path to the resolved .vue source file */
    public string $componentFile;

    /** Parsed attributes from the tag, e.g. ['x' => '0', 'y' => '80', ':value' => 'display'] */
    public array $props;

    /** Child nodes inside the component tag (slot content) */
    /** @var TemplateNode[] */
    public array $slotChildren;

    /** Whether the tag was self-closing (<comp />) */
    public bool $selfClosing;

    /** v5 M3: whether this component is an overlay (auto-assigned to higher layer) */
    public bool $isOverlay = false;

    public function __construct(
        string $tagName,
        string $componentFile,
        array $props,
        array $slotChildren,
        bool $selfClosing,
        int $line = 0
    ) {
        parent::__construct($line);
        $this->tagName       = $tagName;
        $this->componentFile = $componentFile;
        $this->props         = $props;
        $this->slotChildren  = $slotChildren;
        $this->selfClosing   = $selfClosing;
    }
}

/**
 * v6 M5: ScrollContainer node for scrollable list areas
 *
 * Represents a scrollable container that clips its children and provides scrollbar.
 *
 * Syntax:
 *   <scroll-container x="10" y="50" w="380" h="400" :scroll-top="scrollTop">
 *     <list-item ... />
 *   </scroll-container>
 *
 * The SFC compiler measures total content height and marks the container
 * for runtime scroll handling.
 */
class ScrollContainerNode extends TemplateNode
{
    public int $x;
    public int $y;
    public int $w;
    public int $h;

    /** Property name for scroll position binding */
    public string $scrollTopBind;

    /** Scrollable content height (computed at compile-time from children) */
    public int $contentHeight;

    /** Child nodes (list items, etc.) */
    /** @var TemplateNode[] */
    public array $children = [];

    public function __construct(
        int $x,
        int $y,
        int $w,
        int $h,
        string $scrollTopBind = '',
        int $line = 0
    ) {
        parent::__construct($line);
        $this->x = $x;
        $this->y = $y;
        $this->w = $w;
        $this->h = $h;
        $this->scrollTopBind = $scrollTopBind;
        $this->contentHeight = 0;
    }
}
