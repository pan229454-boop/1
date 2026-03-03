/**
 * 极聊 - 前端公共模块
 *
 * 包含：API 请求、Toast 通知、WebSocket 客户端、主题、工具函数等
 * 所有页面均引入此文件
 */

'use strict';

// ================================================================
//  API 请求封装
// ================================================================

const API_BASE = '/api.php';

/**
 * 发起 API 请求
 * @param {string} action 接口名（如 auth/login）
 * @param {object} data   请求体数据
 * @param {string} method GET | POST
 * @returns {Promise<object>} 响应 JSON
 */
async function api(action, data = {}, method = 'POST') {
    const url = method === 'GET'
        ? `${API_BASE}?action=${encodeURIComponent(action)}&${new URLSearchParams(data)}`
        : `${API_BASE}?action=${encodeURIComponent(action)}`;

    const opts = { method, credentials: 'include', headers: { 'Content-Type': 'application/json' } };
    if (method === 'POST') opts.body = JSON.stringify(data);

    try {
        const res  = await fetch(url, opts);
        const json = await res.json();
        return json;
    } catch (e) {
        console.error('[API]', action, e);
        return { code: -1, msg: '网络错误，请检查连接' };
    }
}

/**
 * 上传文件（multipart/form-data）
 * @param {string}    action  接口名
 * @param {FormData}  formData
 * @returns {Promise<object>}
 */
async function apiUpload(action, formData) {
    try {
        const res  = await fetch(`${API_BASE}?action=${encodeURIComponent(action)}`, { method: 'POST', credentials: 'include', body: formData });
        return await res.json();
    } catch (e) {
        return { code: -1, msg: '上传失败' };
    }
}

// ================================================================
//  Toast 通知
// ================================================================

/**
 * 显示 Toast 通知
 * @param {string} msg     消息文本
 * @param {'success'|'error'|'info'|'warning'} type
 * @param {number} duration 显示时长（ms）
 */
function toast(msg, type = 'info', duration = 3000) {
    let container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        document.body.appendChild(container);
    }
    const el = document.createElement('div');
    el.className = `toast ${type}`;
    el.textContent = msg;
    container.appendChild(el);
    setTimeout(() => {
        el.classList.add('out');
        el.addEventListener('animationend', () => el.remove(), { once: true });
    }, duration);
}

// ================================================================
//  主题切换
// ================================================================

/**
 * 初始化主题（读取 localStorage，默认跟随系统）
 */
function initTheme() {
    const saved = localStorage.getItem('jl_theme');
    const sys   = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    applyTheme(saved || sys);
}

/**
 * 切换主题
 * @param {'dark'|'light'|'toggle'} mode
 */
function applyTheme(mode) {
    if (mode === 'toggle') {
        mode = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
    }
    document.documentElement.dataset.theme = mode;
    localStorage.setItem('jl_theme', mode);
}

// ================================================================
//  界面皮肤（主色调 + 侧边栏 + 气泡样式）
// ================================================================

const UI_THEMES = {
  default:        { label: '极聊默认',  badge: '🔵', preview: 'linear-gradient(135deg,#4f73f8,#7a99ff)', vars: {} },
  genshin:        { label: '原神风格',  badge: '✨', preview: 'linear-gradient(135deg,#c49a1c,#8b6914)', vars: {'--primary':'#c49a1c','--primary-dark':'#8b6914','--primary-light':'#e8c040','--accent':'#c44040','--bg-sidebar':'#1e1208','--bubble-self':'#c49a1c'} },
  wutheringwaves: { label: '鸣潮风格',  badge: '🌀', preview: 'linear-gradient(135deg,#0d6e8a,#00bfa5)', vars: {'--primary':'#00bfa5','--primary-dark':'#008f7a','--primary-light':'#40d9c8','--accent':'#ff6b35','--bg-sidebar':'#0a1e24','--bubble-self':'#0d8a7b'} },
  hok:            { label: '王者荣耀',  badge: '👑', preview: 'linear-gradient(135deg,#6d1515,#d4af37)', vars: {'--primary':'#d4af37','--primary-dark':'#a88620','--primary-light':'#f0d060','--accent':'#c41c1c','--bg-sidebar':'#1a0808','--bubble-self':'#b8860b'} },
  pubg:           { label: '和平精英',  badge: '🪖', preview: 'linear-gradient(135deg,#3d5a1e,#7a9e4e)', vars: {'--primary':'#7a9e4e','--primary-dark':'#5a7a30','--primary-light':'#9cc464','--accent':'#c47a1c','--bg-sidebar':'#1a2408','--bubble-self':'#5a7830'} },
  anime:          { label: '二次元',    badge: '🌸', preview: 'linear-gradient(135deg,#e91e8c,#a855f7)', vars: {'--primary':'#e91e8c','--primary-dark':'#b2157a','--primary-light':'#f556b0','--accent':'#a855f7','--bg-sidebar':'#1a0820','--bubble-self':'#d9188f'} },
  sakura:         { label: '樱花',      badge: '🌺', preview: 'linear-gradient(135deg,#ff6b9d,#ffb3c8)', vars: {'--primary':'#ff6b9d','--primary-dark':'#e05580','--primary-light':'#ffa0be','--accent':'#ff9f55','--bg-sidebar':'#200810','--bubble-self':'#e85590'} },
  cyberpunk:      { label: '赛博朋克',  badge: '⚡', preview: 'linear-gradient(135deg,#0f0f23,#b14fff 50%,#00ffff)', vars: {'--primary':'#b14fff','--primary-dark':'#8a30d4','--primary-light':'#d080ff','--accent':'#00ffff','--bg-sidebar':'#080815','--bubble-self':'#7b2de0'} },
  ocean:          { label: '深海蓝',    badge: '🌊', preview: 'linear-gradient(135deg,#0c4a6e,#0891b2)', vars: {'--primary':'#0891b2','--primary-dark':'#0369a1','--primary-light':'#22b8d1','--accent':'#0d9488','--bg-sidebar':'#051520','--bubble-self':'#0780a0'} },
  forest:         { label: '墨林绿',    badge: '🌿', preview: 'linear-gradient(135deg,#052e16,#059669)', vars: {'--primary':'#059669','--primary-dark':'#047857','--primary-light':'#34d399','--accent':'#78716c','--bg-sidebar':'#021208','--bubble-self':'#047857'} },
};

/**
 * 应用 UI 皮肤（将 CSS 变量覆盖到 :root）
 * @param {string} name  皮肤 key（见 UI_THEMES）
 */
function applyUITheme(name = 'default') {
    const allVarKeys = ['--primary','--primary-dark','--primary-light','--accent','--bg-sidebar','--bubble-self'];
    const root = document.documentElement;
    allVarKeys.forEach(k => root.style.removeProperty(k));
    const t = UI_THEMES[name];
    if (t) Object.entries(t.vars).forEach(([k, v]) => root.style.setProperty(k, v));
    localStorage.setItem('jl_skin', name);
}

/**
 * 从 LocalStorage 快速恢复上次选择的皮肤（无需网络请求）
 */
function initUITheme() {
    const saved = localStorage.getItem('jl_skin');
    if (saved && saved !== 'default') applyUITheme(saved);
}

// ================================================================
//  WebSocket 客户端
// ================================================================

class JLSocket {
    /**
     * @param {string}   url       WebSocket 地址（ws:// 或 wss://）
     * @param {object}   handlers  事件处理器 { onmsg, onopen, onclose, onauth }
     */
    constructor(url, handlers = {}) {
        this.url        = url;
        this.handlers   = handlers;
        this.ws         = null;
        this.connected  = false;
        this.retryCount = 0;
        this.maxRetry   = 10;
        this.retryDelay = 2000;     // 重连基础间隔 ms
        this.pingTimer  = null;
        this.pingInterval = 15000; // 心跳间隔 ms（移动端用短间隔保活）
        this.sessionId  = this._getSessionId();
        this._queue     = [];       // 断线期间缓存的待发包
        this._wasDisconnected = false; // 是否曾发生过断线
    }

    /**
     * 读取当前 PHP Session ID（由登录成功后注入页面）
     * @returns {string}
     */
    _getSessionId() {
        // 注入方式：<meta name="session-id" content="xxx">
        const meta = document.querySelector('meta[name="session-id"]');
        return meta ? meta.content : '';
    }

    /** 建立连接 */
    connect() {
        if (this.ws && (this.ws.readyState === WebSocket.OPEN || this.ws.readyState === WebSocket.CONNECTING)) return;
        try {
            this.ws = new WebSocket(this.url);
        } catch (e) {
            console.warn('[JLSocket] 无法创建 WebSocket:', e);
            this._scheduleRetry();
            return;
        }

        this.ws.onopen = () => {
            console.info('[JLSocket] 已连接');
            this.connected  = true;
            this.retryCount = 0;
            // 发送认证包
            this._send({ type: 'auth', session_id: this.sessionId });
            // 启动心跳
            this._startPing();
            this.handlers.onopen?.();
        };

        this.ws.onmessage = (e) => {
            let pkt;
            try { pkt = JSON.parse(e.data); } catch { return; }
            this._handlePacket(pkt);
        };

        this.ws.onclose = (e) => {
            console.info('[JLSocket] 连接断开', e.code);
            this.connected = false;
            this._stopPing();
            if (e.code !== 1000) this._wasDisconnected = true; // 标记曾断线
            this.handlers.onclose?.(e);
            if (e.code !== 1000) this._scheduleRetry(); // 非正常关闭则重连
        };

        this.ws.onerror = (e) => {
            console.error('[JLSocket] 错误', e);
        };
    }

    /** 关闭连接 */
    close() {
        this._stopPing();
        if (this.ws) { this.ws.close(1000, 'user logout'); this.ws = null; }
    }

    /**
     * 发送消息，断线时自动入队列并触发重连（不再弹打扰用户的 Toast）
     * @param {object} packet
     */
    sendMsg(packet) {
        if (!this.connected) {
            this._queue.push(packet);
            this.connect();
            return;
        }
        this._send(packet);
    }

    /** 处理收到的数据包 */
    _handlePacket(pkt) {
        switch (pkt.type) {
            case 'auth_ok':
                this.handlers.onauth?.(pkt, this._wasDisconnected);
                this._wasDisconnected = false; // 恢复后清除标记
                this._flushQueue(); // auth 成功后将断线期间排队的消息一次性发出
                break;
            case 'pong':
                // 心跳响应，不做处理
                break;
            case 'msg':
            case 'recall':
            case 'unread':
                this.handlers.onmsg?.(pkt);
                break;
            case 'error':
                toast(pkt.msg, 'error');
                break;
            default:
                this.handlers.onmsg?.(pkt);
        }
    }

    /** 发送原始 JSON */
    _send(obj) {
        if (this.ws && this.ws.readyState === WebSocket.OPEN) {
            this.ws.send(JSON.stringify(obj));
        }
    }

    /** 将队列中的消息全部发出 */
    _flushQueue() {
        if (!this._queue.length) return;
        const q = this._queue.splice(0);
        q.forEach(pkt => this._send(pkt));
    }

    /** 启动心跳定时 */
    _startPing() {
        this._stopPing();
        this.pingTimer = setInterval(() => {
            this._send({ type: 'ping' });
        }, this.pingInterval);
    }

    /** 停止心跳 */
    _stopPing() {
        if (this.pingTimer) { clearInterval(this.pingTimer); this.pingTimer = null; }
    }

    /** 安排重连 */
    _scheduleRetry() {
        if (this.retryCount >= this.maxRetry) {
            toast('连接服务器失败，请刷新页面重试', 'error', 8000);
            return;
        }
        const delay = Math.min(this.retryDelay * Math.pow(1.5, this.retryCount), 30000);
        this.retryCount++;
        // 第 1 次静默重连，第 2 次起才提示用户
        if (this.retryCount >= 2) {
            toast(`连接中断，正在重连…`, 'warning', 2000);
        }
        console.info(`[JLSocket] ${delay/1000}s 后第 ${this.retryCount} 次重连…`);
        setTimeout(() => this.connect(), delay);
    }
}

// ================================================================
//  加载遮罩
// ================================================================

let _loadingEl = null;

/**
 * 显示全屏加载遮罩
 * @param {string} msg 提示文字
 */
function showLoading(msg = '加载中…') {
    if (_loadingEl) return;
    _loadingEl = document.createElement('div');
    _loadingEl.className = 'loading-overlay';
    _loadingEl.innerHTML = `<div class="spinner"></div><p>${msg}</p>`;
    document.body.appendChild(_loadingEl);
}

/** 隐藏加载遮罩 */
function hideLoading() {
    if (_loadingEl) { _loadingEl.remove(); _loadingEl = null; }
}

// ================================================================
//  模态框辅助
// ================================================================

/**
 * 创建并显示模态框
 * @param {object} opts { title, body(html string), onConfirm, confirmText, destructive }
 * @returns {object} { close }
 */
function showModal({ title = '', body = '', onConfirm = null, confirmText = '确定', confirmStyle = '', cancelText = '取消', onCancel = null, destructive = false } = {}) {
    const backdrop = document.createElement('div');
    backdrop.className = 'modal-backdrop';

    const dangerClass = destructive ? 'btn-danger' : 'btn-primary';
    const extraStyle  = confirmStyle ? ` style="${confirmStyle}"` : '';
    backdrop.innerHTML = `
    <div class="modal" role="dialog" aria-modal="true">
      <div class="modal-header">
        <span class="modal-title">${title}</span>
        <button class="modal-close" aria-label="关闭">
          <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M1 1l12 12M13 1L1 13" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
        </button>
      </div>
      <div class="modal-body">${body}</div>
      ${onConfirm ? `<div class="modal-footer"><button class="btn btn-ghost btn-sm js-cancel">${cancelText}</button><button class="btn ${dangerClass} btn-sm js-confirm"${extraStyle}>${confirmText}</button></div>` : ''}
    </div>`;

    const close = () => backdrop.remove();
    backdrop.querySelector('.modal-close')?.addEventListener('click', () => { onCancel?.(); close(); });
    backdrop.querySelector('.js-cancel')?.addEventListener('click', () => { onCancel?.(); close(); });
    backdrop.querySelector('.js-confirm')?.addEventListener('click', async () => {
        const keepOpen = await onConfirm?.();
        if (!keepOpen) close();
    });
    backdrop.addEventListener('click', (e) => { if (e.target === backdrop) { onCancel?.(); close(); } });
    document.body.appendChild(backdrop);
    return { close };
}

// ================================================================
//  右键 / 长按上下文菜单
// ================================================================

/**
 * 显示上下文菜单
 * @param {MouseEvent|TouchEvent} e 触发事件
 * @param {Array<{label, icon, action, danger}>} items 菜单项
 */
function showContextMenu(e, items) {
    // 移除已有菜单
    document.querySelectorAll('.context-menu').forEach(m => m.remove());

    const menu = document.createElement('div');
    menu.className = 'context-menu';

    items.forEach(item => {
        if (item === 'divider') {
            menu.insertAdjacentHTML('beforeend', '<div class="context-menu-divider"></div>');
            return;
        }
        const el = document.createElement('div');
        el.className = 'context-menu-item' + (item.danger ? ' danger' : '');
        el.innerHTML = `${item.icon ? `<span style="font-size:1.1em">${item.icon}</span>` : ''}<span>${item.label}</span>`;
        el.addEventListener('click', () => { menu.remove(); item.action?.(); });
        menu.appendChild(el);
    });

    // 定位
    const x = e.clientX ?? (e.touches?.[0]?.clientX ?? 0);
    const y = e.clientY ?? (e.touches?.[0]?.clientY ?? 0);
    menu.style.cssText = `left:${x}px;top:${y}px`;
    document.body.appendChild(menu);

    // 边界检测
    requestAnimationFrame(() => {
        const rect = menu.getBoundingClientRect();
        if (rect.right > window.innerWidth)  menu.style.left = `${x - rect.width}px`;
        if (rect.bottom > window.innerHeight) menu.style.top = `${y - rect.height}px`;
    });

    // 点击外部关闭
    const handler = (ev) => { if (!menu.contains(ev.target)) { menu.remove(); document.removeEventListener('click', handler); } };
    setTimeout(() => document.addEventListener('click', handler), 0);
}

// ================================================================
//  图片灯箱
// ================================================================

/**
 * 在灯箱中显示图片
 * @param {string} src 图片地址
 */
function showLightbox(src) {
    const lb = document.createElement('div');
    lb.className = 'lightbox';
    lb.innerHTML = `<img src="${src}" alt="图片预览">`;
    lb.addEventListener('click', () => lb.remove());
    document.body.appendChild(lb);
}

// ================================================================
//  工具函数
// ================================================================

/**
 * 格式化时间戳
 * @param {string|number} ts 时间字符串或 Unix 时间戳
 * @returns {string} 友好时间（今天显示 HH:MM，否则显示日期）
 */
function formatTime(ts) {
    const d    = new Date(typeof ts === 'number' ? ts * 1000 : ts);
    const now  = new Date();
    const isToday = d.toDateString() === now.toDateString();
    const isYesterday = new Date(now - 86400000).toDateString() === d.toDateString();
    const pad = n => String(n).padStart(2, '0');
    const hm  = `${pad(d.getHours())}:${pad(d.getMinutes())}`;

    if (isToday) return hm;
    if (isYesterday) return `昨天 ${hm}`;
    if (now.getFullYear() === d.getFullYear()) return `${d.getMonth()+1}/${d.getDate()} ${hm}`;
    return `${d.getFullYear()}/${d.getMonth()+1}/${d.getDate()}`;
}

/**
 * 相对时间（x秒前、x分钟前…）
 * @param {string|number} ts
 * @returns {string}
 */
function relativeTime(ts) {
    const diff = Math.floor((Date.now() - new Date(ts)) / 1000);
    if (diff < 60)     return `${diff}秒前`;
    if (diff < 3600)   return `${Math.floor(diff/60)}分钟前`;
    if (diff < 86400)  return `${Math.floor(diff/3600)}小时前`;
    return `${Math.floor(diff/86400)}天前`;
}

/**
 * 对内容做 XSS 安全转义
 * @param {string} str
 * @returns {string}
 */
function escHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

/**
 * 解析消息内容：将 URL 转为链接，@xxx 高亮
 * @param {string} text
 * @returns {string} HTML
 */
function parseContent(text) {
    let s = escHtml(text);
    // URL 超链接
    s = s.replace(/(https?:\/\/[^\s<>"]+)/g, '<a href="$1" target="_blank" rel="noopener">$1</a>');
    // @提及高亮
    s = s.replace(/@(\S+)/g, '<span class="text-primary-color font-semibold">@$1</span>');
    return s;
}

/**
 * 生成头像占位符（取昵称首字，指定颜色）
 * @param {string} nickname
 * @param {string} size  CSS 尺寸类（avatar-sm 等）
 * @returns {string} HTML
 */
function avatarHtml(nickname = '?', size = 'avatar-md') {
    const colors = ['#4f73f8','#f97316','#22c55e','#ec4899','#a855f7','#06b6d4','#ef4444'];
    const color  = colors[nickname.charCodeAt(0) % colors.length];
    const char   = [...nickname][0] ?? '?';
    const sizeMap = { 'avatar-xs': '28px', 'avatar-sm': '36px', 'avatar-md': '44px', 'avatar-lg': '60px', 'avatar-xl': '80px' };
    const px = sizeMap[size] || '44px';
    return `<span class="avatar-placeholder ${size}" style="background:${color};width:${px};height:${px};font-size:calc(${px} * .45)">${char}</span>`;
}

/**
 * 显示/隐藏密码
 * @param {HTMLInputElement} input
 * @param {HTMLElement}      btn
 */
function togglePasswordVisibility(input, btn) {
    const isText = input.type === 'text';
    input.type = isText ? 'password' : 'text';
    btn.textContent = isText ? '👁' : '🙈';
}

/**
 * 防抖函数
 * @param {Function} fn
 * @param {number}   delay ms
 * @returns {Function}
 */
function debounce(fn, delay = 300) {
    let t;
    return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), delay); };
}

/**
 * 节流函数
 * @param {Function} fn
 * @param {number}   interval ms
 * @returns {Function}
 */
function throttle(fn, interval = 200) {
    let last = 0;
    return (...args) => { const now = Date.now(); if (now - last >= interval) { last = now; fn(...args); } };
}

/**
 * 滚动至底部
 * @param {HTMLElement} el  可滚动容器
 * @param {boolean}     smooth 是否平滑滚动
 */
function scrollToBottom(el, smooth = true) {
    el.scrollTo({ top: el.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
}

/**
 * 检查是否滚动到底部（允许 40px 误差）
 * @param {HTMLElement} el
 * @returns {boolean}
 */
function isAtBottom(el) {
    return el.scrollHeight - el.scrollTop - el.clientHeight < 40;
}

// ================================================================
//  滑块验证码（纯前端演示版，生产环境建议对接服务端验证）
// ================================================================

class SliderCaptcha {
    /**
     * @param {HTMLElement} container  .slider-captcha 容器元素
     * @param {Function}    onSuccess  验证成功回调
     */
    constructor(container, onSuccess) {
        this.container  = container;
        this.onSuccess  = onSuccess;
        this.verified   = false;
        this._init();
    }

    _init() {
        this.container.innerHTML = `
          <div class="slider-text">拖动滑块完成验证</div>
          <div class="slider-track">
            <div class="slider-fill"></div>
          </div>
          <div class="slider-thumb">&#8594;</div>`;

        this.thumb = this.container.querySelector('.slider-thumb');
        this.fill  = this.container.querySelector('.slider-fill');
        this.text  = this.container.querySelector('.slider-text');
        this.trackW = 0;
        this._bindEvents();
    }

    _bindEvents() {
        let startX = 0, isDragging = false;
        const trackRect = () => this.container.querySelector('.slider-track').getBoundingClientRect();

        const start = (e) => {
            if (this.verified) return;
            isDragging = true;
            startX = (e.touches?.[0] ?? e).clientX;
            this.trackW = trackRect().width;
        };

        const move = (e) => {
            if (!isDragging) return;
            e.preventDefault();
            const cx    = (e.touches?.[0] ?? e).clientX;
            const dist  = Math.max(0, Math.min(cx - startX, this.trackW - 36));
            const pct   = dist / (this.trackW - 36);
            this.thumb.style.left = `${dist}px`;
            this.fill.style.width = `${dist + 18}px`;
            // 拖到 >=90%认为通过（演示）
            if (pct >= 0.9) this._succeed();
        };

        const end = () => {
            if (!isDragging) return;
            isDragging = false;
            if (!this.verified) this.reset();
        };

        this.thumb.addEventListener('mousedown',  start);
        this.thumb.addEventListener('touchstart', start, { passive: true });
        document.addEventListener('mousemove', move);
        document.addEventListener('touchmove', move, { passive: false });
        document.addEventListener('mouseup',  end);
        document.addEventListener('touchend', end);
    }

    _succeed() {
        this.verified = true;
        this.container.classList.add('success');
        this.text.textContent = '验证通过 ✓';
        this.thumb.textContent = '✓';
        this.onSuccess?.();
    }

    reset() {
        this.verified = false;
        this.thumb.style.left = '0';
        this.fill.style.width = '0';
        this.container.classList.remove('success');
        this.text.textContent = '拖动滑块完成验证';
        this.thumb.innerHTML = '&#8594;';
    }
}

// ================================================================
//  页面初始化入口
// ================================================================

document.addEventListener('DOMContentLoaded', () => {
    // 1. 主题初始化
    initTheme();
    // 1b. UI 皮肤快速恢复（从 localStorage）
    initUITheme();
    // 2. 全局主题切换按钮（class="js-theme-toggle"）
    document.querySelectorAll('.js-theme-toggle').forEach(btn => {
        btn.addEventListener('click', () => applyTheme('toggle'));
    });

    // 3. 所有图片点击放大（class="js-lightbox"）
    document.addEventListener('click', (e) => {
        const img = e.target.closest('.js-lightbox');
        if (img) showLightbox(img.src || img.dataset.src);
    });
});

// 导出（供其他 module 使用，若不使用打包工具可忽略）
// export { api, apiUpload, toast, JLSocket, showLoading, hideLoading, showModal, showContextMenu, showLightbox, formatTime, relativeTime, escHtml, parseContent, avatarHtml, debounce, throttle, scrollToBottom, isAtBottom, SliderCaptcha };
