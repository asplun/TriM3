<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit; ?>
<?php if (tri_show_right()): ?>
<aside class="col-right" id="tri3-rightbar">
  <div class="col-right-inner tw-side-right">
    <!-- v1.2.63：目录格独立为 Swup 容器；非文章页输出空壳，侧栏其余部分常驻不重建 -->
    <div class="tri3-side-dyn" id="tri3-side-dyn">
    <?php
    /* 文章页悬浮导航：有右栏时渲染在右栏顶部（post.php 已设置 $GLOBALS['__tri_toc_render']） */
    if (!empty($GLOBALS['__tri_toc_render']) && $this->is('post')): ?>
    <nav class="tri3-toc tri3-toc-side" data-tri3-toc aria-label="文章目录">
      <div class="tri3-toc-head"><i class="ri-list-unordered"></i><span>目录</span></div>
      <div class="tri3-toc-body"><ol class="tri3-toc-list"></ol></div>
    </nav>
    <?php endif; ?>
    </div>
    <?php if (function_exists('tri_widgets_render_list')): ?>
      <?php echo tri_widgets_render_list('right'); ?>
    <?php endif; ?>
  </div>
</aside>
<?php else: ?>
<!-- v1.2.63：无右栏页面也保留同名容器，避免 Swup 容器缺失 -->
<div class="tri3-side-dyn" id="tri3-side-dyn" hidden></div>
<?php endif; ?>
