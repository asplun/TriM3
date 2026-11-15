<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit;
/**
 * 独立页评论区（首页菜单局部切换渲染用）
 * 结构与文章页 comments.php 一致：一体式聊天窗（.tr-chat-card）+ 气泡评论 + 贴底输入栏
 *
 * 需要变量：
 *   $triPageCid          页面 cid
 *   $triPagePermalink    页面 permalink（评论提交地址）
 *   $triPageAllowComment 是否允许评论（'1'/'0'）
 *   $triPageSlug         页面 slug（分页链接）
 *   $triPageCommentPage  当前评论页码（可选）
 */
$triDb2 = \Typecho\Db::get();
$triCmtCount = (int) $triDb2->fetchObject(
    $triDb2->select(['COUNT(*)' => 'c'])
        ->from('table.comments')
        ->where('cid = ?', (int) $triPageCid)
        ->where('type = ?', 'comment')
        ->where('status = ?', 'approved')
)->c;
$triCmtSize = 20;
$triCmtTotalPage = max(1, (int) ceil($triCmtCount / $triCmtSize));
$triCmtPage = max(1, min((int) ($triPageCommentPage ?? 1), $triCmtTotalPage));
/* 排序：默认倒序（最新在上），尊重 cookie / ?commentSort= 参数（与文章页一致） */
$triCmtSort = tri3_comment_sort();
$triCmtOrder = $triCmtSort === 'asc' ? \Typecho\Db::SORT_ASC : \Typecho\Db::SORT_DESC;
$triComments = $triDb2->fetchAll($triDb2->query(
    $triDb2->select()
        ->from('table.comments')
        ->where('cid = ?', (int) $triPageCid)
        ->where('type = ?', 'comment')
        ->where('status = ?', 'approved')
        ->order('table.comments.coid', $triCmtOrder)
        ->page($triCmtPage, $triCmtSize)
));
$triCmtUser = \Typecho\Widget::widget('Widget_User');
$triCmtSec = \Typecho\Widget::widget('Widget_Security');
/* 博主评论徽章：Typecho 默认管理员 authorId=1 */
$triCmtOwnerId = 1;
?>
<div id="comments" class="md-card comments-section">
  <!-- 窗口头部：用户信息 + 标题计数 -->
  <div class="comment-head">
    <div class="comment-head-user">
      <span class="comment-head-avatar">
        <?php if ($triCmtUser->hasLogin()): ?>
          <img src="<?php echo tri3_gravatar_url((string) $triCmtUser->mail, 36); ?>" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer">
        <?php else: ?>
          <span class="comment-head-av-fallback"><i class="ri-user-3-line"></i></span>
        <?php endif; ?>
        <i class="comment-head-dot"></i>
      </span>
      <span class="comment-head-name">
        <?php if ($triCmtUser->hasLogin()): ?>
          <?php echo htmlspecialchars((string) $triCmtUser->screenName); ?>
        <?php else: ?>
          <a href="javascript:;" id="comment-info-toggle" class="comment-head-info-link">填写资料</a>
        <?php endif; ?>
      </span>
    </div>
    <div class="comment-head-right">
      <h3 class="widget-title comment-title"><i class="ri-chat-3-line"></i>评论区</h3>
      <span class="comment-count"><?php echo $triCmtCount; ?></span>
      <span class="comment-sort">
        <a href="<?php echo tri3_sort_url('desc'); ?>" class="comment-sort-desc<?php echo $triCmtSort === 'desc' ? ' is-active' : ''; ?>" title="最新在前"><i class="ri-arrow-down-line"></i></a>
        <a href="<?php echo tri3_sort_url('asc'); ?>" class="comment-sort-asc<?php echo $triCmtSort === 'asc' ? ' is-active' : ''; ?>" title="最早在前"><i class="ri-arrow-up-line"></i></a>
      </span>
    </div>
  </div>

  <!-- 一体式聊天窗：消息区 + 输入栏 -->
  <div class="tr-chat-card">
    <div class="chat-list-area">
      <?php if ($triComments): ?>
      <ol class="comment-list">
        <?php foreach ($triComments as $triC):
            $triIsOwner = (int) ($triC['authorId'] ?? 0) === $triCmtOwnerId;
            $triAv = tri3_comment_avatar((string) ($triC['qq'] ?? ''), (string) $triC['mail'], 40);
            $triCname = htmlspecialchars((string) ($triC['author'] !== '' ? $triC['author'] : '匿名'));
            $triCtime = date('Y-m-d H:i', (int) $triC['created']);
        ?>
        <li id="comment-<?php echo (int) $triC['coid']; ?>" class="comment-item<?php echo $triIsOwner ? ' comment-by-author' : ''; ?>">
          <?php if ($triAv !== ''): ?>
          <span class="comment-avatar"><img src="<?php echo $triAv; ?>" alt="" loading="lazy"><?php if ($triIsOwner): ?><i class="comment-avatar-dot"></i><?php endif; ?></span>
          <?php endif; ?>
          <div class="comment-body">
            <div class="comment-meta">
              <span class="comment-author"><?php echo $triCname; ?><?php if ($triIsOwner): ?><i class="comment-badge">博主</i><?php endif; ?></span>
              <span class="comment-meta-dot">·</span>
              <time class="comment-time" datetime="<?php echo date('c', (int) $triC['created']); ?>" title="<?php echo $triCtime; ?>"><?php echo tri3_time_ago((int) $triC['created']); ?></time>
              <span class="comment-reply-spacer"></span>
              <button type="button" class="comment-reply-btn" data-coid="<?php echo (int) $triC['coid']; ?>" data-author="<?php echo $triCname; ?>">回复</button>
              <?php if ($triCmtUser->hasLogin() && $triCmtUser->pass('administrator')): ?><button type="button" class="comment-del-btn" data-coid="<?php echo (int) $triC['coid']; ?>">删除</button><?php endif; ?>
            </div>
            <div class="comment-bubble">
              <div class="comment-content"><?php echo tri3_comment_text((string) $triC['text']); ?></div>
            </div>
          </div>
        </li>
        <?php endforeach; ?>
      </ol>
      <?php if ($triCmtTotalPage > 1): ?>
      <nav class="md-pagination comment-pagination">
        <?php for ($triI = 1; $triI <= $triCmtTotalPage; $triI++): ?>
        <span class="<?php echo $triI === $triCmtPage ? 'current' : ''; ?>">
          <?php if ($triI === $triCmtPage): ?><?php echo $triI; ?>
          <?php else: ?><a href="<?php echo $triPagePermalink; ?>/commentPage/<?php echo $triI; ?>"><?php echo $triI; ?></a>
          <?php endif; ?>
        </span>
        <?php endfor; ?>
      </nav>
      <?php endif; ?>
      <?php else: ?>
      <div class="comment-empty"><i class="ri-chat-smile-3-line"></i><p>还没有评论，来说两句吧</p></div>
      <?php endif; ?>
    </div>

    <!-- 输入栏（一体，贴底） -->
    <?php if ($triPageAllowComment === '1'): ?>
    <div id="respond-<?php echo (int) $triPageCid; ?>" class="respond">
      <form method="post" action="<?php echo htmlspecialchars($triPagePermalink); ?>/comment" id="comment-form" role="form" class="chat-input-form" data-cid="<?php echo (int) $triPageCid; ?>">
        <input type="hidden" name="_" value="<?php echo htmlspecialchars((string) $triCmtSec->getToken($triCmtSec->request->getRequestUrl())); ?>" />
        <input type="hidden" name="pageurl" value="<?php echo htmlspecialchars((string) $triPagePermalink, ENT_QUOTES, 'UTF-8'); ?>" />
        <input type="hidden" name="parent" value="0" />
        <input type="text" name="tri3_hp" value="" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px;width:1px;height:1px;opacity:0;pointer-events:none" />
        <?php if (!$triCmtUser->hasLogin()): ?>
        <div class="comment-guest-panel" id="comment-guest-panel">
          <input type="text" name="author" id="author" class="text" placeholder="昵称" required />
          <input type="email" name="mail" id="mail" class="text" placeholder="邮箱" required />
          <input type="url" name="url" id="url" class="text" placeholder="网站（可选）" />
          <input type="text" name="qq" id="qq" class="text" placeholder="QQ 号（可选）" value="<?php echo tri3_comment_qq_value(); ?>" maxlength="20" />
        </div>
        <?php else: ?>
        <div class="comment-form-user"><a href="<?php echo (string) \Typecho\Widget::widget('Widget_Options')->profileUrl; ?>"><?php echo htmlspecialchars((string) $triCmtUser->screenName); ?></a> · <a href="<?php echo (string) \Typecho\Widget::widget('Widget_Options')->logoutUrl; ?>" data-tri3-local>退出登录</a></div>
        <?php endif; ?>
        <div class="reply-target-bar" id="reply-target-bar" hidden>
          <span class="reply-target-label"><i class="ri-corner-down-right-line"></i> 回复给 <b id="reply-target-name"></b></span>
          <button type="button" class="reply-target-cancel" id="reply-target-cancel">取消回复</button>
        </div>
        <div class="chat-input-bar">
          <button type="button" class="chat-emoji-btn" id="comment-emoji-toggle" data-emoji-picker data-emoji-target="#textarea" title="表情" aria-label="表情"><i class="ri-emotion-line"></i></button>
          <button type="button" class="chat-emoji-btn" id="comment-img-toggle" data-img-picker data-img-target="#textarea" title="插入图片" aria-label="插入图片"><i class="ri-image-add-line"></i></button>
          <textarea rows="1" cols="50" name="text" id="textarea" class="textarea" placeholder="写下你的看法…" required></textarea>
          <button type="submit" class="comment-submit" id="comment-submit"><i class="ri-send-plane-2-line"></i>发送</button>
        </div>
      </form>
    </div>
    <?php else: ?>
    <p class="comment-closed"><i class="ri-lock-line"></i>评论已关闭</p>
    <?php endif; ?>
  </div>
</div>
