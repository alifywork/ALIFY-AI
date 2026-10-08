document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-alify-copy]').forEach(function (button) {
    button.addEventListener('click', async function () {
      var selector = button.getAttribute('data-alify-copy');
      var target = document.querySelector(selector);
      if (!target) return;
      var text = target.value || target.textContent || '';
      var original = button.textContent;
      try {
        await navigator.clipboard.writeText(text.trim());
      } catch (e) {
        if (target.select) {
          target.select();
          document.execCommand('copy');
        }
      }
      button.textContent = 'Copied ✓';
      button.disabled = true;
      setTimeout(function () {
        button.textContent = original;
        button.disabled = false;
      }, 1400);
    });
  });

  document.querySelectorAll('[data-confirm]').forEach(function (el) {
    el.addEventListener('click', function (event) {
      if (!window.confirm(el.getAttribute('data-confirm'))) {
        event.preventDefault();
      }
    });
  });
});
