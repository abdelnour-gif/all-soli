/* Darja Bot — ويدجت الدردشة لأي موقع.
   الاستعمال:  <script src="https://YOUR-SERVER/widget.js" data-shop="boutique-leila" defer></script>
   اختياري:   data-color="#0f7b5f"  data-position="left|right"  data-label="سقسينا" */
(function () {
  "use strict";
  var script = document.currentScript || document.querySelector('script[src*="widget.js"][data-shop]');
  if (!script || window.__darjaBotLoaded) return;
  window.__darjaBotLoaded = true;
  var shop = script.getAttribute("data-shop");
  if (!shop) return;
  var base = new URL(script.src, location.href).origin;
  var color = script.getAttribute("data-color") || "#0f7b5f";
  var side = script.getAttribute("data-position") === "left" ? "left" : "right";
  var label = script.getAttribute("data-label") || "سقسينا 💬";

  var css = document.createElement("style");
  css.textContent =
    ".djb-btn{position:fixed;bottom:20px;" + side + ":20px;z-index:2147483000;border:0;border-radius:28px;padding:12px 18px;" +
    "background:" + color + ";color:#fff;font:600 15px Cairo,system-ui,sans-serif;box-shadow:0 4px 14px rgba(0,0,0,.2);cursor:pointer}" +
    ".djb-frame{position:fixed;bottom:84px;" + side + ":20px;z-index:2147483000;width:370px;height:560px;max-height:calc(100vh - 110px);" +
    "border:0;border-radius:16px;box-shadow:0 10px 40px rgba(0,0,0,.25);background:#fff;display:none}" +
    ".djb-frame.open{display:block}" +
    "@media (max-width:480px){.djb-frame{width:calc(100vw - 24px);" + side + ":12px;bottom:78px;height:calc(100vh - 100px)}}";
  document.head.appendChild(css);

  var frame = document.createElement("iframe");
  frame.className = "djb-frame";
  frame.title = "Chat";
  frame.setAttribute("loading", "lazy");
  var btn = document.createElement("button");
  btn.className = "djb-btn";
  btn.type = "button";
  btn.textContent = label;
  btn.setAttribute("aria-expanded", "false");
  btn.addEventListener("click", function () {
    if (!frame.src) frame.src = base + "/c/" + encodeURIComponent(shop) + "?embed=1";
    var open = frame.classList.toggle("open");
    btn.textContent = open ? "✕" : label;
    btn.setAttribute("aria-expanded", open ? "true" : "false");
  });
  function mount() { document.body.appendChild(frame); document.body.appendChild(btn); }
  if (document.body) mount(); else document.addEventListener("DOMContentLoaded", mount);
})();
