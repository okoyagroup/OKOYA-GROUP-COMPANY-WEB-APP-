<?php if (!empty($_SESSION['auth'])): ?>
<!-- CHAT WIDGET -->
<div id="chatWidget">
  <button class="chat-fab" id="chatFab" title="Messages">
    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
    <span class="chat-badge" id="chatBadge" style="display:none">0</span>
  </button>

  <div class="chat-panel" id="chatPanel" style="display:none">
    <!-- Header -->
    <div class="chat-header">
      <div class="chat-header-left">
        <div class="chat-back" id="chatBack" style="display:none">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5"/><path d="m12 19-7-7 7-7"/></svg>
        </div>
        <div class="chat-header-info">
          <strong id="chatTitle">Messages</strong>
          <small id="chatSubtitle">Select a conversation</small>
        </div>
      </div>
      <button class="chat-close" id="chatClose">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>

    <!-- User List -->
    <div class="chat-users" id="chatUsers"></div>

    <!-- Messages Area -->
    <div class="chat-messages" id="chatMessages" style="display:none"></div>

    <!-- Input Area -->
    <div class="chat-input-area" id="chatInputArea" style="display:none">
      <input type="text" id="chatInput" placeholder="Type a message..." maxlength="2000" autocomplete="off">
      <button class="chat-send" id="chatSend">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
      </button>
    </div>
  </div>
</div>

<style>
#chatWidget{position:fixed;bottom:24px;right:24px;z-index:9000;font-family:'Manrope',sans-serif}
.chat-fab{width:56px;height:56px;border-radius:50%;background:linear-gradient(135deg,#059669,#047857);color:#fff;border:none;display:grid;place-items:center;cursor:pointer;box-shadow:0 4px 16px rgba(5,150,105,.35);transition:all .3s cubic-bezier(.34,1.56,.64,1);position:relative}
.chat-fab:hover{transform:scale(1.1);box-shadow:0 6px 24px rgba(5,150,105,.45)}
.chat-badge{position:absolute;top:-2px;right:-2px;background:#dc2626;color:#fff;font-size:10px;font-weight:800;min-width:20px;height:20px;border-radius:99px;display:flex;align-items:center;justify-content:center;padding:0 5px;border:2px solid #fff}
.chat-panel{position:absolute;bottom:70px;right:0;width:380px;max-height:560px;background:#fff;border:1px solid #e2e8f0;border-radius:20px;box-shadow:0 20px 40px rgba(0,0,0,.12);overflow:hidden;display:flex;flex-direction:column;animation:chatSlideUp .3s cubic-bezier(.34,1.56,.64,1)}
@keyframes chatSlideUp{from{opacity:0;transform:translateY(20px) scale(.95)}to{opacity:1;transform:translateY(0) scale(1)}}
.chat-header{padding:16px 18px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;background:#fff;flex-shrink:0}
.chat-header-left{display:flex;align-items:center;gap:10px}
.chat-back{width:32px;height:32px;border-radius:8px;border:1px solid #e2e8f0;background:#f8fafc;display:grid;place-items:center;cursor:pointer;color:#475569;transition:all .2s}
.chat-back:hover{background:#059669;color:#fff;border-color:#059669}
.chat-header-info strong{font-family:'Sora',sans-serif;font-size:14px;font-weight:800;color:#0f172a;display:block;line-height:1.2}
.chat-header-info small{font-size:11px;color:#94a3b8;font-weight:600}
.chat-close{width:32px;height:32px;border-radius:8px;border:1px solid #e2e8f0;background:#f8fafc;display:grid;place-items:center;cursor:pointer;color:#475569;transition:all .2s}
.chat-close:hover{background:#fef2f2;color:#dc2626;border-color:#fecaca}
.chat-users{flex:1;overflow-y:auto;padding:8px}
.chat-user-item{display:flex;align-items:center;gap:12px;padding:12px 14px;border-radius:12px;cursor:pointer;transition:all .2s;border:1px solid transparent}
.chat-user-item:hover{background:#f8fafc;border-color:#e2e8f0}
.chat-user-item.active{background:#ecfdf5;border-color:#059669}
.chat-user-avatar{width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,#059669,#047857);color:#fff;display:grid;place-items:center;font-family:'Sora',sans-serif;font-size:14px;font-weight:800;flex-shrink:0;position:relative}
.chat-user-avatar .status-dot{position:absolute;bottom:1px;right:1px;width:10px;height:10px;border-radius:50%;border:2px solid #fff}
.status-dot.online{background:#22c55e}
.status-dot.offline{background:#94a3b8}
.chat-user-info{flex:1;min-width:0}
.chat-user-name{font-size:13px;font-weight:700;color:#0f172a;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.chat-user-meta{font-size:11px;color:#94a3b8;font-weight:600;margin-top:2px}
.chat-user-unread{background:#dc2626;color:#fff;font-size:10px;font-weight:800;min-width:20px;height:20px;border-radius:99px;display:flex;align-items:center;justify-content:center;padding:0 5px;flex-shrink:0}
.chat-messages{flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:8px;background:#f8fafc}
.chat-msg{max-width:78%;padding:10px 14px;border-radius:16px;font-size:13px;font-weight:600;line-height:1.5;word-wrap:break-word;animation:msgFadeIn .3s ease}
@keyframes msgFadeIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
.chat-msg.mine{align-self:flex-end;background:linear-gradient(135deg,#059669,#047857);color:#fff;border-bottom-right-radius:4px}
.chat-msg.theirs{align-self:flex-start;background:#fff;color:#0f172a;border:1px solid #e2e8f0;border-bottom-left-radius:4px}
.chat-msg-time{font-size:9px;opacity:.7;margin-top:4px;font-weight:700}
.chat-empty{text-align:center;padding:40px 20px;color:#94a3b8;font-size:13px;font-weight:600}
.chat-input-area{padding:12px 14px;border-top:1px solid #e2e8f0;display:flex;gap:8px;align-items:center;background:#fff;flex-shrink:0}
.chat-input-area input{flex:1;height:40px;border:1.5px solid #e2e8f0;border-radius:12px;padding:0 14px;font-size:13px;font-weight:600;font-family:'Manrope',sans-serif;outline:none;transition:border-color .2s;background:#f8fafc}
.chat-input-area input:focus{border-color:#059669;background:#fff}
.chat-send{width:40px;height:40px;border-radius:12px;background:linear-gradient(135deg,#059669,#047857);color:#fff;border:none;display:grid;place-items:center;cursor:pointer;transition:all .2s;flex-shrink:0}
.chat-send:hover{transform:scale(1.05);box-shadow:0 4px 12px rgba(5,150,105,.3)}
.chat-send:disabled{opacity:.5;cursor:not-allowed;transform:none}
@media(max-width:480px){
  #chatWidget{bottom:16px;right:16px}
  .chat-panel{width:calc(100vw - 32px);max-height:70vh;bottom:68px}
  .chat-fab{width:50px;height:50px}
}
</style>

<script>
(function(){
  const API = '/chat_api.php';
  let currentChatUser = null;
  let pollTimer = null;
  let badgeTimer = null;
  let isOpen = false;

  const $ = id => document.getElementById(id);
  const fab = $('chatFab');
  const panel = $('chatPanel');
  const badge = $('chatBadge');
  const usersEl = $('chatUsers');
  const msgsEl = $('chatMessages');
  const inputArea = $('chatInputArea');
  const input = $('chatInput');
  const sendBtn = $('chatSend');
  const backBtn = $('chatBack');
  const closeBtn = $('chatClose');
  const titleEl = $('chatTitle');
  const subtitleEl = $('chatSubtitle');

  function esc(s){const d=document.createElement('div');d.textContent=s;return d.innerHTML;}
  function fmtTime(ts){if(!ts)return'';const d=new Date(ts);const now=new Date();const diff=now-d;if(diff<60000)return'Just now';if(diff<3600000)return Math.floor(diff/60000)+'m ago';if(diff<86400000)return d.toLocaleTimeString('en-NG',{hour:'2-digit',minute:'2-digit',hour12:true});return d.toLocaleDateString('en-NG',{day:'numeric',month:'short'});}

  // Toggle panel
  fab.addEventListener('click', () => {
    isOpen = !isOpen;
    panel.style.display = isOpen ? 'flex' : 'none';
    if (isOpen) loadUsers();
  });
  closeBtn.addEventListener('click', () => { isOpen = false; panel.style.display = 'none'; });

  // Load users list
  async function loadUsers(){
    try {
      const res = await fetch(API + '?action=get_users');
      const data = await res.json();
      if (!data.users) return;
      let html = '';
      data.users.forEach(u => {
        const initials = u.full_name.split(' ').map(w=>w[0]).join('').substring(0,2).toUpperCase();
        const activeClass = currentChatUser && currentChatUser.id == u.id ? ' active' : '';
        const statusClass = u.is_online ? 'online' : 'offline';
        const unreadHtml = u.unread_count > 0 ? '<span class="chat-user-unread">'+u.unread_count+'</span>' : '';
        html += '<div class="chat-user-item'+activeClass+'" data-id="'+u.id+'" data-name="'+esc(u.full_name)+'">';
        html += '<div class="chat-user-avatar">'+esc(initials)+'<span class="status-dot '+statusClass+'"></span></div>';
        html += '<div class="chat-user-info"><span class="chat-user-name">'+esc(u.full_name)+'</span><span class="chat-user-meta">'+esc(u.staff_code||'')+(u.last_message_time?' · '+fmtTime(u.last_message_time):'')+'</span></div>';
        html += unreadHtml + '</div>';
      });
      usersEl.innerHTML = html || '<div class="chat-empty">No users available</div>';
      usersEl.querySelectorAll('.chat-user-item').forEach(el => {
        el.addEventListener('click', () => openConversation(parseInt(el.dataset.id), el.dataset.name));
      });
    } catch(e){ console.error('Chat: loadUsers failed', e); }
  }

  // Open conversation
  async function openConversation(userId, userName){
    currentChatUser = { id: userId, name: userName };
    usersEl.style.display = 'none';
    msgsEl.style.display = 'flex';
    inputArea.style.display = 'flex';
    backBtn.style.display = 'grid';
    titleEl.textContent = userName;
    subtitleEl.textContent = 'Online';
    input.value = '';
    input.focus();
    await loadMessages();
    await markRead();
    startPolling();
  }

  // Back to user list
  backBtn.addEventListener('click', () => {
    currentChatUser = null;
    stopPolling();
    msgsEl.style.display = 'none';
    inputArea.style.display = 'none';
    usersEl.style.display = 'block';
    backBtn.style.display = 'none';
    titleEl.textContent = 'Messages';
    subtitleEl.textContent = 'Select a conversation';
    loadUsers();
  });

  // Load messages
  async function loadMessages(){
    if (!currentChatUser) return;
    try {
      const res = await fetch(API + '?action=get_messages&user_id=' + currentChatUser.id);
      const data = await res.json();
      if (!data.messages) return;
      let html = '';
      if (data.messages.length === 0) {
        html = '<div class="chat-empty">No messages yet. Say hello!</div>';
      } else {
        data.messages.forEach(m => {
          const cls = parseInt(m.sender_id) === data.my_id ? 'mine' : 'theirs';
          html += '<div class="chat-msg '+cls+'">'+esc(m.message)+'<div class="chat-msg-time">'+fmtTime(m.created_at)+'</div></div>';
        });
      }
      msgsEl.innerHTML = html;
      msgsEl.scrollTop = msgsEl.scrollHeight;
    } catch(e){ console.error('Chat: loadMessages failed', e); }
  }

  // Send message
  async function sendMessage(){
    const msg = input.value.trim();
    if (!msg || !currentChatUser) return;
    input.value = '';
    sendBtn.disabled = true;
    try {
      const form = new FormData();
      form.append('action', 'send_message');
      form.append('receiver_id', currentChatUser.id);
      form.append('message', msg);
      await fetch(API, { method: 'POST', body: form });
      await loadMessages();
    } catch(e){ console.error('Chat: send failed', e); }
    sendBtn.disabled = false;
    input.focus();
  }
  sendBtn.addEventListener('click', sendMessage);
  input.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }});

  // Mark read
  async function markRead(){
    if (!currentChatUser) return;
    try {
      const form = new FormData();
      form.append('action', 'mark_read');
      form.append('user_id', currentChatUser.id);
      await fetch(API, { method: 'POST', body: form });
    } catch(e){}
  }

  // Polling
  function startPolling(){ stopPolling(); pollTimer = setInterval(loadMessages, 2000); }
  function stopPolling(){ if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }

  // Badge polling
  async function updateBadge(){
    try {
      const res = await fetch(API + '?action=unread_count');
      const data = await res.json();
      if (data.count > 0) { badge.textContent = data.count > 99 ? '99+' : data.count; badge.style.display = 'flex'; }
      else { badge.style.display = 'none'; }
    } catch(e){}
  }
  badgeTimer = setInterval(updateBadge, 5000);
  updateBadge();
})();
</script>
<?php endif; ?>