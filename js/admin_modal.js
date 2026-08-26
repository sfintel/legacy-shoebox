// Generic show/hide/backdrop/escape wiring for a modal overlay —
// reused by every "Add X" popup across the admin pages (admin_content.php,
// admin_archive.php, admin_settings.php). Each of those pages used to
// show its add-form inline, permanently, above the table it fed; this
// is the shared plumbing behind replacing that with a button that pops
// the same form up in a modal instead. Same open/close/backdrop/Escape
// behavior index.php's own "Add Content" modal already used, factored
// out here so six modals don't each reimplement it.
window.AdminModal = (function () {
  "use strict";

  function wire(modalId, triggerId, closeId) {
    const modal = document.getElementById(modalId);
    const trigger = document.getElementById(triggerId);
    const closeBtn = document.getElementById(closeId);

    function open(e) {
      if (e) e.preventDefault();
      modal.style.display = "flex";
    }
    function close() {
      modal.style.display = "none";
    }

    trigger.addEventListener("click", open);
    closeBtn.addEventListener("click", close);
    modal.addEventListener("click", (e) => {
      if (e.target === modal) close(); // backdrop click
    });
    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape" && modal.style.display !== "none") close();
    });

    return { open, close };
  }

  return { wire };
})();
