<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit; ?>

<?php
/* ========== 定制评论列表结构（参考 0True / Inspir-X：一体式群聊窗） ========== */
if (!function_exists('threadedComments')) {

function threadedComments($comments, $options) {
    $isOwner = $comments->authorId && $comments->authorId == $comments->ownerId;
    $triAv = tri3_comment_avatar((string) $comments->qq, (string) $comments->mail, 40);
    $triAuthor = $comments->url
        ? '<a href="' . htmlspecialchars((string) $comments->url) . '" rel="external nofollow" target="_blank">' . htmlspecialchars((string) $comments->author) . '</a>'
        : htmlspecialchars((string) $comments->author);
?>
<li id="comment-<?php $comments->theId(); ?>" class="comment-item<?php echo $isOwner ? ' comment-by-author' : ''; ?><?php echo $comments->levels > 0 ? ' comment-child' : ' comment-parent'; ?>">
  <?php if ($triAv !== ''): ?>
  <span class="comment-avatar">
    <img src="<?php echo $triAv; ?>" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer">
    <?php if ($isOwner): ?><i class="comment-avatar-dot"></i><?php endif; ?>
  </span>
  <?php endif; ?>
  <div class="comment-body">
    <div class="comment-meta">
      <span class="comment-author"><?php echo $triAuthor; ?><?php if ($isOwner): ?><i class="comment-badge">博主</i><?php endif; ?></span>
      <span class="comment-meta-dot">·</span>
      <time class="comment-time" datetime="<?php echo date('c', (int)$comments->created); ?>" title="<?php echo date('Y-m-d H:i', (int)$comments->created); ?>"><?php echo tri3_time_ago((int)$comments->created); ?></time>
      <span class="comment-reply-spacer"></span>
      <button type="button" class="comment-reply-btn" data-coid="<?php echo (int)$comments->coid; ?>" data-author="<?php echo htmlspecialchars((string) $comments->author); ?>">回复</button>
      <?php if (tri3_is_admin()): ?><button type="button" class="comment-del-btn" data-coid="<?php echo (int)$comments->coid; ?>">删除</button><?php endif; ?>
    </div>
    <div class="comment-bubble">
      <div class="comment-content"><?php echo nl2br(tri3_qq_emoji(htmlspecialchars((string) $comments->text))); ?></div>
      <?php if ('waiting' == $comments->status): ?><span class="comment-waiting">评论正在等待审核</span><?php endif; ?>
    </div>
  </div>
  <?php if ($comments->children): ?>
  <div class="comment-children"><?php $comments->threadedComments($options); ?></div>
  <?php endif; ?>
</li>
<?php } }
?>

<div id="comments" class="md-card comments-section tri3-reveal main-reveal">
  <?php $this->comments()->to($comments); ?>

  <!-- 窗口头部：用户信息（0True 风格）+ 标题计数 -->
  <div class="comment-head">
    <div class="comment-head-user">
      <span class="comment-head-avatar">
        <?php if ($this->user->hasLogin()): ?>
          <img src="<?php echo tri3_gravatar_url((string) $this->user->mail, 36); ?>" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer">
        <?php else: ?>
          <span class="comment-head-av-fallback"><i class="ri-user-3-line"></i></span>
        <?php endif; ?>
        <i class="comment-head-dot"></i>
      </span>
      <span class="comment-head-name">
        <?php if ($this->user->hasLogin()): ?>
          <?php $this->user->screenName(); ?>
        <?php else: ?>
          <a href="javascript:;" id="comment-info-toggle" class="comment-head-info-link">填写资料</a>
        <?php endif; ?>
      </span>
    </div>
    <div class="comment-head-right">
      <h3 class="widget-title comment-title"><i class="ri-chat-3-line"></i>评论区</h3>
      <span class="comment-count"><?php echo tri3_comment_count($this); ?></span>
      <span class="comment-sort">
        <a href="<?php echo tri3_sort_url('desc'); ?>" class="comment-sort-desc<?php echo tri3_comment_sort() === 'desc' ? ' is-active' : ''; ?>" title="最新在前"><i class="ri-arrow-down-line"></i></a>
        <a href="<?php echo tri3_sort_url('asc'); ?>" class="comment-sort-asc<?php echo tri3_comment_sort() === 'asc' ? ' is-active' : ''; ?>" title="最早在前"><i class="ri-arrow-up-line"></i></a>
      </span>
    </div>
  </div>

  <!-- 一体式聊天窗：消息区 + 输入栏 -->
  <div class="tr-chat-card">
    <!-- 消息区（可滚动） -->
    <div class="chat-list-area">
      <?php tri3_render_comment_tree($this); ?>
    </div>

    <!-- 输入栏（一体，贴底） -->
    <?php if ($this->allow('comment')): ?>
    <div id="<?php $this->respondId(); ?>" class="respond">
      <div class="cancel-comment-reply"><?php $comments->cancelReply(); ?></div>
      <form method="post" action="<?php $this->commentUrl(); ?>" id="comment-form" role="form" class="chat-input-form" data-cid="<?php $this->cid(); ?>">
        <input type="hidden" name="_" value="<?php echo $this->security->getToken($this->request->getRequestUrl()); ?>" />
        <input type="hidden" name="pageurl" value="<?php echo htmlspecialchars((string) $this->permalink, ENT_QUOTES, 'UTF-8'); ?>" />
        <input type="hidden" name="parent" value="0" />
        <input type="text" name="tri3_hp" value="" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px;width:1px;height:1px;opacity:0;pointer-events:none" />
        <?php if (!$this->user->hasLogin()): ?>
        <div class="comment-guest-panel" id="comment-guest-panel"<?php if ($this->remember('author', true) || $this->remember('mail', true)): ?> style="display:grid"<?php endif; ?>>
          <input type="text" name="author" id="author" class="text" placeholder="昵称" value="<?php $this->remember('author'); ?>" required />
          <input type="email" name="mail" id="mail" class="text" placeholder="邮箱<?php if ($this->options->commentsRequireMail): ?>（必填）<?php endif; ?>" value="<?php $this->remember('mail'); ?>"<?php if ($this->options->commentsRequireMail): ?> required<?php endif; ?> />
          <input type="url" name="url" id="url" class="text" placeholder="网站（可选）" value="<?php $this->remember('url'); ?>" />
          <input type="text" name="qq" id="qq" class="text" placeholder="QQ 号（可选）" value="<?php echo tri3_comment_qq_value(); ?>" maxlength="20" />
        </div>
        <?php else: ?>
        <div class="comment-form-user"><a href="<?php $this->options->profileUrl(); ?>"><?php $this->user->screenName(); ?></a> · <a href="<?php $this->options->logoutUrl(); ?>" data-tri3-local>退出登录</a></div>
        <?php endif; ?>
        <div class="reply-target-bar" id="reply-target-bar" hidden>
          <span class="reply-target-label"><i class="ri-corner-down-right-line"></i> 回复给 <b id="reply-target-name"></b></span>
          <button type="button" class="reply-target-cancel" id="reply-target-cancel">取消回复</button>
        </div>
        <div class="chat-input-bar">
          <button type="button" class="chat-emoji-btn" id="comment-emoji-toggle" data-emoji-picker data-emoji-target="#textarea" title="表情" aria-label="表情"><i class="ri-emotion-line"></i></button>
          <button type="button" class="chat-emoji-btn" id="comment-img-toggle" data-img-picker data-img-target="#textarea" title="插入图片" aria-label="插入图片"><i class="ri-image-add-line"></i></button>
          <textarea rows="1" cols="50" name="text" id="textarea" class="textarea" placeholder="写下你的看法…" required><?php $this->remember('text'); ?></textarea>
          <button type="submit" class="comment-submit" id="comment-submit"><i class="ri-send-plane-2-line"></i>发送</button>
        </div>
      </form>
    </div>
    <?php else: ?>
    <p class="comment-closed"><i class="ri-lock-line"></i>评论已关闭</p>
    <?php endif; ?>
  </div>
</div>

<script>
(function () {
  var t = document.getElementById('comment-info-toggle');
  var p = document.getElementById('comment-guest-panel');
  if (t && p) {
    t.addEventListener('click', function () {
      var show = p.style.display === 'grid';
      p.style.display = show ? 'none' : 'grid';
      t.textContent = show ? '填写资料' : '收起';
    });
  }
})();
</script>
