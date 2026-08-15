// Shared browser-side WebAuthn helpers — used by account.php (register
// a passkey) and login.php (sign in with one). All binary fields cross
// the wire as base64url strings (see includes/webauthn_helper.php's
// $useBase64UrlEncoding=true) since the browser's WebAuthn API deals in
// raw ArrayBuffers but JSON has no binary type — this module is the
// conversion layer between the two.
(function (global) {
  "use strict";

  function b64urlToBuffer(b64url) {
    const pad = "=".repeat((4 - (b64url.length % 4)) % 4);
    const base64 = (b64url + pad).replace(/-/g, "+").replace(/_/g, "/");
    const raw = atob(base64);
    const buf = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i++) buf[i] = raw.charCodeAt(i);
    return buf.buffer;
  }

  function bufferToB64url(buf) {
    const bytes = new Uint8Array(buf);
    let str = "";
    for (let i = 0; i < bytes.byteLength; i++) str += String.fromCharCode(bytes[i]);
    return btoa(str).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
  }

  function prepareCreateOptions(args) {
    const pk = args.publicKey;
    const out = Object.assign({}, pk);
    out.challenge = b64urlToBuffer(pk.challenge);
    out.user = Object.assign({}, pk.user, { id: b64urlToBuffer(pk.user.id) });
    if (Array.isArray(pk.excludeCredentials)) {
      out.excludeCredentials = pk.excludeCredentials.map((c) =>
        Object.assign({}, c, { id: b64urlToBuffer(c.id) }));
    }
    return out;
  }

  function prepareGetOptions(args) {
    const pk = args.publicKey;
    const out = Object.assign({}, pk);
    out.challenge = b64urlToBuffer(pk.challenge);
    if (Array.isArray(pk.allowCredentials)) {
      out.allowCredentials = pk.allowCredentials.map((c) =>
        Object.assign({}, c, { id: b64urlToBuffer(c.id) }));
    }
    return out;
  }

  // Registers a new passkey for the ALREADY-AUTHENTICATED current user
  // (account.php). optionsUrl/verifyUrl are the two server round-trips;
  // label is the user-chosen friendly name for this device.
  async function registerPasskey(optionsUrl, verifyUrl, label) {
    const optRes = await fetch(optionsUrl, { method: "POST" });
    const optData = await optRes.json();
    if (!optRes.ok) throw new Error(optData.error || "Could not start passkey registration.");

    const publicKey = prepareCreateOptions(optData);
    const cred = await navigator.credentials.create({ publicKey });

    const verifyRes = await fetch(verifyUrl, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        clientDataJSON: bufferToB64url(cred.response.clientDataJSON),
        attestationObject: bufferToB64url(cred.response.attestationObject),
        label: label || "",
      }),
    });
    const verifyData = await verifyRes.json();
    if (!verifyRes.ok) throw new Error(verifyData.error || "Passkey registration failed.");
    return verifyData.credential;
  }

  // Signs in with an existing passkey (login.php) — email identifies
  // which account's registered credentials to offer; the ceremony
  // itself proves possession of one of them. On success the server has
  // already called auth_login(), so the caller just needs to redirect.
  async function loginWithPasskey(email, optionsUrl, verifyUrl) {
    const optRes = await fetch(optionsUrl, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ email }),
    });
    const optData = await optRes.json();
    if (!optRes.ok) throw new Error(optData.error || "No passkey available for that account.");

    const publicKey = prepareGetOptions(optData);
    const assertion = await navigator.credentials.get({ publicKey });

    const verifyRes = await fetch(verifyUrl, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        credentialId: bufferToB64url(assertion.rawId),
        clientDataJSON: bufferToB64url(assertion.response.clientDataJSON),
        authenticatorData: bufferToB64url(assertion.response.authenticatorData),
        signature: bufferToB64url(assertion.response.signature),
      }),
    });
    const verifyData = await verifyRes.json();
    if (!verifyRes.ok) throw new Error(verifyData.error || "Passkey sign-in failed.");
    return verifyData;
  }

  global.PasskeyAuth = {
    registerPasskey,
    loginWithPasskey,
    isSupported: () => !!(global.PublicKeyCredential),
  };
})(window);
