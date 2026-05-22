<template>
  <app x="0" y="0" w="640" h="480" title="VueCalc v6 M4 Test">
    <rect x="0" y="0" w="640" h="480" class="main-bg" />

    <!-- 顶部工具栏 (Flex布局) -->
    <flex direction="row" x="0" y="0" w="640" h="40" gap="8" class="toolbar">
      <text x="10" y="10" :bind="titleText" class="title-text" fontSize="16" color="#FFFFFF" />
    </flex>

    <!-- 文本编辑区域 -->
    <textbox x="10" y="50" w="620" h="380"
             v-model="content"
             placeholder="在此输入文本..."
             class="editor"
             @keydown="onKeyDown"
             @enter="onEnter" />

    <!-- 底部状态栏 (Flex布局) -->
    <flex direction="row" x="0" y="440" w="640" h="40" gap="8" class="statusbar">
      <text x="10" y="450" :bind="statusText" class="status-text" fontSize="12" color="#FFFFFF" />
    </flex>
  </app>
</template>

<script lang="php">
class AppComponent extends ReactiveComponent
{
    public string $titleText = 'VueCalc v6 M4 Test';
    public string $content = 'Hello, VueCalc!';
    public string $statusText = 'Ready | Press Enter to submit';

    public function onKeyDown(int $key): void
    {
        $this->statusText = 'Key: ' . $key;
    }

    public function onEnter(?string $arg = null): void
    {
        $len = strlen($this->content);
        $preview = $len > 20 ? substr($this->content, 0, 20) . '...' : $this->content;
        $this->statusText = 'Submitted: ' . $preview;
    }
}
</script>

<style>
app { background: #1E1E1E; }
.main-bg { background: #1E1E1E; }
.toolbar { background: #2D2D2D; }
.title-text { color: #FFFFFF; }
.editor { background: #252526; color: #D4D4D4; font-size: 14px; }
.statusbar { background: #007ACC; }
.status-text { color: #FFFFFF; }
</style>