// Shared by admin_content.php's own "Add content" form and index.php's
// "Add Content" modal — same fields, same type-based visibility rules,
// same keyword picker, same multipart submit to /api/admin/content.php,
// so there's exactly one implementation of this rather than two that
// could drift. Both pages load this before their own page-specific
// script (js/admin_content.js / js/app.js).
window.ContentForm = (function () {
  "use strict";

  function esc(str) {
    return String(str == null ? "" : str).replace(/[&<>"']/g, (c) => ({
      "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;",
    }[c]));
  }

  // Shared by the add-content form and each item's edit row (the latter
  // only exists on admin_content.php): selected chips (removable), an
  // input to type a new keyword, and a row of suggestion chips. Callers
  // supply getAllTags() rather than this module reading a page-specific
  // item list directly — admin_content.js already has every item's tags
  // in memory (currentItems); index.php's modal doesn't load the full
  // content list, so it fetches its own suggestion source once when the
  // modal first opens (see initAddForm below).
  function renderTagPicker(containerId, selected, inputId, getAllTags) {
    const allTags = [...new Set((getAllTags() || []))].sort();
    const container = document.getElementById(containerId);
    const state = { tags: [...selected] };

    // Typing a keyword only ever landed in state.tags via the input's own
    // Enter/comma keydown handler (see draw() below) — clicking a
    // Save/Add button directly, with text still sitting uncommitted in
    // the box, submitted the form without that keyword ever being read
    // anywhere, silently dropping it. Every submit path (this form's own
    // submit handler in initAddForm, and each caller's
    // collectAddFields/collectEditFields/saveEdit in admin_content.js
    // and admin_archive.js) now calls this first so a still-typed
    // keyword is captured the same way pressing Enter would have.
    state.commitPending = function commitPending() {
      const input = container.querySelector(".tag-picker-input");
      if (!input) return;
      const val = input.value.trim().replace(/,$/, "");
      if (val && !state.tags.includes(val)) {
        state.tags.push(val);
        input.value = "";
      }
    };

    // Only touches the suggestions sub-element, never the input itself —
    // rebuilding the whole container on every keystroke (as draw() does)
    // would drop focus/cursor position out from under the person typing.
    function renderSuggestions(filterText) {
      const suggestionsEl = container.querySelector(".tag-picker-suggestions");
      if (!suggestionsEl) return;
      const matches = allTags.filter((t) =>
        !state.tags.includes(t) && (!filterText || t.toLowerCase().includes(filterText))
      );
      suggestionsEl.innerHTML = matches.map((t) =>
        `<button type="button" class="tag-chip" data-tag="${esc(t)}">${esc(t)}</button>`
      ).join("");
      suggestionsEl.querySelectorAll(".tag-chip").forEach((chip) => {
        chip.addEventListener("click", () => {
          if (!state.tags.includes(chip.dataset.tag)) state.tags.push(chip.dataset.tag);
          draw();
        });
      });
    }

    function draw() {
      const selectedHtml = state.tags.map((t) =>
        `<button type="button" class="tag-chip active" data-tag="${esc(t)}">${esc(t)} &times;</button>`
      ).join("");
      container.innerHTML = `
        <div class="tag-picker-selected">${selectedHtml}</div>
        <input type="text" id="${inputId}" class="tag-picker-input" placeholder="Add a keyword and press Enter…" autocomplete="off">
        <div class="tag-picker-suggestions"></div>
      `;
      container.querySelectorAll(".tag-picker-selected .tag-chip").forEach((chip) => {
        chip.addEventListener("click", () => {
          state.tags = state.tags.filter((t) => t !== chip.dataset.tag);
          draw();
        });
      });
      renderSuggestions("");
      const input = container.querySelector(".tag-picker-input");
      input.addEventListener("input", () => {
        renderSuggestions(input.value.trim().toLowerCase());
      });
      input.addEventListener("keydown", (e) => {
        if (e.key === "Enter" || e.key === ",") {
          e.preventDefault();
          const val = input.value.trim().replace(/,$/, "");
          if (val) {
            if (!state.tags.includes(val)) state.tags.push(val);
            input.value = "";
            draw();
          }
        }
      });
    }
    draw();
    return state;
  }

  // Wires up one add-content <form> — type-based field visibility,
  // greying out the unused file/URL field once the other has a value,
  // the keyword picker, and the multipart submit. opts:
  //   - getAllTags(): string[] — keyword suggestion source
  //   - onSuccess(item): called after a successful POST; caller decides
  //     what happens next (admin_content.js reloads its table; the
  //     index.php modal closes itself)
  function initAddForm(opts) {
    const typeSelect = document.getElementById("typeSelect");
    const mediaKindRow = document.getElementById("mediaKindRow");
    const mediaKindSelect = document.getElementById("mediaKindSelect");
    const textRow = document.getElementById("textRow");
    const fileRow = document.getElementById("fileRow");
    const urlRow = document.getElementById("urlRow");
    const mediaUrlRow = document.getElementById("mediaUrlRow");
    const descRow = document.getElementById("descRow");
    const form = document.getElementById("contentForm");
    const submitBtn = document.getElementById("submitBtn");
    const formError = document.getElementById("formError");
    const fileInput = document.getElementById("fileInput");
    const mediaUrlInput = document.getElementById("mediaUrlInput");
    const fileRemoveBtn = document.getElementById("fileRemoveBtn");

    let tagPickerState = { tags: [] };

    function refreshTagPicker() {
      tagPickerState = renderTagPicker("tagsPicker", tagPickerState.tags, "tagsPickerInput", opts.getAllTags);
    }
    refreshTagPicker();

    // Native file inputs have no built-in way to un-select a file once
    // one's chosen (re-opening the OS picker and clicking Cancel leaves
    // whatever was already selected in place) — this button is the only
    // way to clear it, which also matters now that a chosen file greys
    // out (and disables) the URL field below via syncMediaExclusivity().
    function syncFileRemoveBtn() {
      fileRemoveBtn.style.display = fileInput.files.length > 0 ? "" : "none";
    }
    fileRemoveBtn.addEventListener("click", () => {
      fileInput.value = "";
      syncFileRemoveBtn();
      syncMediaExclusivity();
    });

    // File and "download from URL" are mutually exclusive (enforced at
    // submit time regardless — see below); this just greys out whichever
    // option isn't in use once the choice is clear, instead of leaving
    // both looking equally live. Only meaningful for Photo and the
    // combined Video/Audio+Transcript type ("recording"), the only types
    // where mediaUrlRow is ever shown.
    function syncMediaExclusivity() {
      syncFileRemoveBtn();
      if (mediaUrlRow.style.display === "none") {
        fileInput.disabled = false;
        mediaUrlInput.disabled = false;
        fileRow.classList.remove("field-disabled");
        mediaUrlRow.classList.remove("field-disabled");
        return;
      }
      const hasFile = fileInput.files.length > 0;
      const hasUrl = mediaUrlInput.value.trim() !== "";
      mediaUrlInput.disabled = hasFile;
      mediaUrlRow.classList.toggle("field-disabled", hasFile);
      fileInput.disabled = hasUrl;
      fileRow.classList.toggle("field-disabled", hasUrl);
    }
    fileInput.addEventListener("change", syncMediaExclusivity);
    mediaUrlInput.addEventListener("input", syncMediaExclusivity);

    function syncFormFields() {
      // A file/URL chosen under one type is stale once you switch away
      // from it (the row hides, but a native file input silently keeps
      // whatever was already selected) — clearing on every type change
      // means coming back to the same type never shows a leftover
      // filename from before. Harmless on the other two callers of this
      // function (the very first call, and right after a successful
      // submit): both already start from an empty file input anyway.
      fileInput.value = "";
      mediaUrlInput.value = "";

      const isRecording = typeSelect.value === "recording"; // combined Video/Audio + Transcript
      const isPhoto = typeSelect.value === "photo";
      const isUrl = typeSelect.value === "url";
      const isStory = typeSelect.value === "story";
      const isDocument = typeSelect.value === "document";
      const isAudio = isRecording && mediaKindSelect.value === "audio";
      // A recording's transcript is optional — a media file/URL and/or a
      // transcript is required (validated at submit time below), unlike
      // Story/Document where the text box is the one required field.
      const showText = isRecording || isStory || isDocument;
      const requireText = isStory || isDocument;
      const isMedia = isPhoto || isRecording; // file+URL+caption fields
      mediaKindRow.style.display = isRecording ? "" : "none";
      textRow.style.display = showText ? "" : "none";
      fileRow.style.display = (isMedia || isDocument) ? "" : "none";
      mediaUrlRow.style.display = isMedia ? "" : "none";
      urlRow.style.display = isUrl ? "" : "none";
      descRow.style.display = isMedia ? "" : "none";
      document.getElementById("textLabel").textContent = isStory ? "Story" : (isDocument ? "Document text" : "Transcript text (optional)");
      document.getElementById("textInput").placeholder = isStory
        ? "What's the story? Write it as you'd tell it…"
        : (isDocument ? "Paste the document's text here (e.g. a letter or email)…"
          : (isRecording ? "Paste the transcript text here, if you have one yet…" : "Paste the transcript text here…"));
      document.getElementById("storyHint").style.display = isStory ? "" : "none";
      document.getElementById("textInput").required = requireText;
      // Neither fileInput nor mediaUrlInput is marked required here — for
      // media types exactly one of them is required, which plain HTML
      // can't express (and for a recording, neither is required at all
      // as long as a transcript is given instead); the submit handler
      // below validates that instead. A document's file is fully
      // optional either way.
      fileInput.required = false;
      fileInput.multiple = isPhoto;
      document.getElementById("fileLabel").textContent = isPhoto ? "Photo(s)"
        : (isDocument ? "Attach original (optional)" : (isAudio ? "Audio file (optional if a transcript is given)" : "Video file (optional if a transcript is given)"));
      document.getElementById("fileHint").style.display = (isPhoto || isDocument) ? "" : "none";
      document.getElementById("fileHint").textContent = isPhoto
        ? "Select up to 10 related photos to add them as one album."
        : "Optional — a scan or PDF of the original, kept for reference. The Ask tab only reads the pasted text above, never this file.";
      mediaUrlInput.required = false;
      document.getElementById("urlInput").required = isUrl;
      document.getElementById("titleInput").required = !isUrl;
      syncMediaExclusivity();
    }
    typeSelect.addEventListener("change", syncFormFields);
    mediaKindSelect.addEventListener("change", syncFormFields);
    syncFormFields();

    form.addEventListener("submit", async (e) => {
      e.preventDefault();
      formError.style.display = "none";

      const type = typeSelect.value;
      const isRecording = type === "recording"; // combined Video/Audio + Transcript
      const isPhoto = type === "photo";
      const isMedia = isPhoto || isRecording;
      const hasFile = fileInput.files.length > 0;
      const mediaUrl = mediaUrlInput.value.trim();
      const hasUrl = mediaUrl !== "";
      const hasText = document.getElementById("textInput").value.trim() !== "";
      // Photo still requires exactly one of file/url, same as always. A
      // recording's file/URL is mutually exclusive too, but — unlike
      // Photo — both may be entirely absent as long as a transcript is
      // given instead (mirrors the server-side check in
      // api/admin/content.php's "recording" branch, which is the
      // authoritative one; this is just a fast client-side echo of it).
      if (isPhoto && hasFile === hasUrl) {
        formError.textContent = "Provide exactly one: a file, or a URL to download from.";
        formError.style.display = "";
        return;
      }
      if (isRecording) {
        if (hasFile && hasUrl) {
          formError.textContent = "Provide exactly one: a file, or a URL to download from, not both.";
          formError.style.display = "";
          return;
        }
        if (!hasFile && !hasUrl && !hasText) {
          formError.textContent = "Provide a recording (file or URL) and/or a transcript.";
          formError.style.display = "";
          return;
        }
      }

      const originalBtnText = submitBtn.textContent;
      submitBtn.disabled = true;
      // Every submission does real server-side work before responding —
      // a URL download can take a while for a large file, and
      // transcript/document/story/URL content additionally run a
      // synchronous AI narrative-note pass that can take several seconds
      // — so every path gets the same animated-dots treatment the Ask
      // tab's pending reply uses, not just the URL-download case (which
      // used to be the only one that showed anything at all, leaving
      // every other submission looking stuck with no feedback).
      const label = isMedia && mediaUrl !== "" ? "Downloading" : "Adding";
      submitBtn.textContent = "";
      submitBtn.appendChild(document.createTextNode(label));
      const dots = document.createElement("span");
      dots.className = "typing-dots";
      dots.setAttribute("aria-hidden", "true");
      dots.style.marginLeft = "6px";
      for (let i = 0; i < 3; i++) dots.appendChild(document.createElement("span"));
      submitBtn.appendChild(dots);
      try {
        tagPickerState.commitPending();
        const formData = new FormData(form);
        tagPickerState.tags.forEach((t) => formData.append("tags[]", t));
        const res = await fetch("/api/admin/content.php", {
          method: "POST",
          body: formData,
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || "Failed to add content");
        form.reset();
        syncFormFields();
        tagPickerState = { tags: [] };
        refreshTagPicker();
        opts.onSuccess(data.item);
      } catch (err) {
        formError.textContent = err.message;
        formError.style.display = "";
      } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = originalBtnText;
      }
    });

    return { refreshTagPicker, syncFormFields };
  }

  // The app-wide keyword suggestion list (includes/keywords.php) —
  // shared fetch helper so admin_content.js, admin_archive.js (Quotes),
  // and index.php's modal all draw from the exact same source instead
  // of each inventing its own (the old behavior: Content's picker
  // scanned currentItems.flatMap(tags), Quotes had no picker at all).
  async function fetchKeywordLabels() {
    try {
      const res = await fetch("/api/admin/keywords.php");
      if (!res.ok) return [];
      const data = await res.json();
      return (data.keywords || []).map((k) => k.label);
    } catch {
      return [];
    }
  }

  // Renders the circled-i "Keywords" indicator used by both the Content
  // table and the Quotes table — a real tooltip (see .info-icon /
  // .info-tooltip in style.css), not the native `title` attribute, since
  // title tooltips don't reliably appear across browsers and effectively
  // never appear on touch devices at all.
  function renderKeywordsIcon(labels) {
    if (!labels || !labels.length) return "—";
    return `<span class="info-icon" tabindex="0">i<span class="info-tooltip">${esc(labels.join(", "))}</span></span>`;
  }

  // Click/tap fallback for touch devices, where :hover never fires —
  // toggles .open on the tapped icon and closes any other open one. Call
  // once per page; event delegation means it keeps working for icons
  // rendered later (e.g. after a table reloads). Hover and keyboard
  // focus (:hover/:focus in CSS) already work without this.
  function wireInfoIcons() {
    document.addEventListener("click", (e) => {
      const icon = e.target.closest(".info-icon");
      document.querySelectorAll(".info-icon.open").forEach((el) => {
        if (el !== icon) el.classList.remove("open");
      });
      if (icon) icon.classList.toggle("open");
    });
  }

  return { renderTagPicker, initAddForm, fetchKeywordLabels, renderKeywordsIcon, wireInfoIcons };
})();
