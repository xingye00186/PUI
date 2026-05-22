<template>
  <app x="0" y="0" w="400" h="500" title="v-for List Test">
    <rect x="0" y="0" w="400" h="500" class="main-bg" />

    <!-- List header -->
    <text x="10" y="10" :bind="listTitle" class="header-text" />

    <!-- v6 M5: ScrollContainer with list items -->
    <scroll-container x="10" y="50" w="380" h="400" :scroll-top="scrollTop">
      <list-item :items="todoItems"
                 :item-height="50"
                 class="item-bg"
                 :text-bind="item.text"
                 @click="deleteItem"
                 :click-arg="item.id" />
    </scroll-container>

    <!-- v6 M5: Add button -->
    <rect x="150" y="460" w="100" h="30" class="add-btn" @click="addItem" />
    <text x="150" y="460" :bind="addBtnText" class="add-btn-text" align="center" container-w="100" container-x="0" />
  </app>
</template>

<script lang="php">
class AppComponent extends ReactiveComponent
{
    public string $listTitle = "Todo List (v6 M5)";
    public string $addBtnText = "Add Item";
    public string $scrollTop = "0";
    public string $todoItems = "[{\"id\":\"1\",\"text\":\"Task 1\"},{\"id\":\"2\",\"text\":\"Task 2\"},{\"id\":\"3\",\"text\":\"Task 3\"}]";

    public function deleteItem(string $indexStr): void
    {
        $index = (int)$indexStr;
        $items = json_decode($this->todoItems, true) ?? [];
        if ($index >= 0 && $index < count($items)) {
            array_splice($items, $index, 1);
        }
        $this->todoItems = json_encode($items);
        $this->dirty = true;
    }

    public function addItem(): void
    {
        $items = json_decode($this->todoItems, true) ?? [];
        $newId = (string)(count($items) + 1);
        $items[] = ["id" => $newId, "text" => "New Task"];
        $this->todoItems = json_encode($items);
        $this->dirty = true;
    }
}
</script>

<style>
.main-bg { background: #2D2D2D; }
.header-text { color: #FFFFFF; font-size: 18px; }
.item-bg { background: #3E3E3E; color: #DDDDDD; font-size: 14px; }
.add-btn { background: #4488CC; }
.add-btn-text { color: #FFFFFF; font-size: 14px; }
</style>