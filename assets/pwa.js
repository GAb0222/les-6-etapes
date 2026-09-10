/* PWA : enregistrement du service worker, installation, mise à jour.
 *
 * Règles UX (voir canvas « PWA & app iOS ») :
 *  - on ne propose jamais l'installation avant une première partie : le bouton
 *    #installButton vit sur l'écran de résultat et reste masqué ailleurs ;
 *  - un refus est mémorisé 30 jours ; une installation le masque pour toujours ;
 *  - une nouvelle version n'est appliquée que hors partie en cours.
 */
(function () {
  "use strict";

  const DISMISS_KEY = "six-etapes:install-dismissed";
  const DISMISS_DAYS = 30;
  const IN_APP = /SixEtapesApp/i.test(navigator.userAgent);
  const STANDALONE =
    window.matchMedia("(display-mode: standalone)").matches || window.navigator.standalone === true;
  const IS_IOS = /iP(hone|ad|od)/.test(navigator.userAgent) && !window.MSStream;
  const IS_SAFARI_IOS = IS_IOS && /Safari/.test(navigator.userAgent) && !/CriOS|FxiOS|EdgiOS/.test(navigator.userAgent);

  const scriptUrl = new URL(document.currentScript ? document.currentScript.src : "assets/pwa.js", location.href);
  const VERSION = scriptUrl.searchParams.get("v") || "dev";

  let deferredPrompt = null;
  let waitingWorker = null;
  let updateBanner = null;

  document.documentElement.classList.toggle("is-standalone", STANDALONE);
  document.documentElement.classList.toggle("is-in-app", IN_APP);

  function syncConnectionClass() {
    document.documentElement.classList.toggle("is-offline", !navigator.onLine);
  }
  syncConnectionClass();
  window.addEventListener("online", syncConnectionClass);
  window.addEventListener("offline", syncConnectionClass);

  // -- Service worker ---------------------------------------------------------

  if ("serviceWorker" in navigator && !IN_APP) {
    window.addEventListener("load", () => {
      navigator.serviceWorker
        .register(`sw.js?v=${encodeURIComponent(VERSION)}`, { scope: "./" })
        .then((registration) => {
          if (registration.waiting && navigator.serviceWorker.controller) {
            waitingWorker = registration.waiting;
            proposeUpdate();
          }
          registration.addEventListener("updatefound", () => {
            const installing = registration.installing;
            if (!installing) return;
            installing.addEventListener("statechange", () => {
              if (installing.state === "installed" && navigator.serviceWorker.controller) {
                waitingWorker = installing;
                proposeUpdate();
              }
            });
          });
        })
        .catch(() => {
          // Pas de service worker (HTTP sans localhost, navigation privée…) : le site reste utilisable.
        });

      let refreshing = false;
      navigator.serviceWorker.addEventListener("controllerchange", () => {
        if (refreshing) return;
        refreshing = true;
        window.location.reload();
      });
    });
  }

  function gameInProgress() {
    return typeof window.sixEtapesGameInProgress === "function" && window.sixEtapesGameInProgress();
  }

  function proposeUpdate() {
    if (!waitingWorker) return;
    if (gameInProgress()) {
      window.setTimeout(proposeUpdate, 15000);
      return;
    }
    showUpdateBanner();
  }

  function showUpdateBanner() {
    if (updateBanner) return;
    updateBanner = document.createElement("div");
    updateBanner.className = "pwa-banner";
    updateBanner.setAttribute("role", "status");
    updateBanner.innerHTML =
      '<span>Nouvelle version disponible.</span>' +
      '<button type="button" class="pwa-banner-action">Actualiser</button>' +
      '<button type="button" class="pwa-banner-close" aria-label="Plus tard">✕</button>';
    updateBanner.querySelector(".pwa-banner-action").addEventListener("click", () => {
      if (waitingWorker) waitingWorker.postMessage("skipWaiting");
    });
    updateBanner.querySelector(".pwa-banner-close").addEventListener("click", () => {
      updateBanner.remove();
      updateBanner = null;
    });
    document.body.appendChild(updateBanner);
  }

  // -- Installation -----------------------------------------------------------

  function installDismissed() {
    try {
      const raw = window.localStorage.getItem(DISMISS_KEY);
      if (!raw) return false;
      if (raw === "installed") return true;
      return Date.now() - Number(raw) < DISMISS_DAYS * 24 * 60 * 60 * 1000;
    } catch {
      return false;
    }
  }

  function rememberDismiss(value) {
    try {
      window.localStorage.setItem(DISMISS_KEY, value);
    } catch {
      // Stockage indisponible : on n'insiste pas.
    }
  }

  function canOfferInstall() {
    if (STANDALONE || IN_APP || installDismissed()) return false;
    return deferredPrompt !== null || IS_SAFARI_IOS;
  }

  function refreshInstallButton() {
    const button = document.querySelector("#installButton");
    if (!button) return;
    button.hidden = !canOfferInstall();
  }

  function toast(text) {
    const el = document.querySelector("#feedbackToast");
    if (!el) {
      window.alert(text);
      return;
    }
    el.textContent = text;
    el.className = "feedback-toast is-visible";
    window.clearTimeout(toast.timer);
    toast.timer = window.setTimeout(() => el.classList.remove("is-visible"), 6000);
  }

  async function install() {
    if (deferredPrompt) {
      const prompt = deferredPrompt;
      deferredPrompt = null;
      try {
        prompt.prompt();
        const choice = await prompt.userChoice;
        if (choice && choice.outcome === "accepted") {
          rememberDismiss("installed");
        } else {
          rememberDismiss(String(Date.now()));
        }
      } catch {
        rememberDismiss(String(Date.now()));
      }
      refreshInstallButton();
      return;
    }
    if (IS_SAFARI_IOS) {
      toast("Dans Safari : touche Partager, puis « Sur l'écran d'accueil ».");
      rememberDismiss(String(Date.now()));
      refreshInstallButton();
    }
  }

  window.addEventListener("beforeinstallprompt", (event) => {
    event.preventDefault();
    deferredPrompt = event;
    refreshInstallButton();
  });

  window.addEventListener("appinstalled", () => {
    deferredPrompt = null;
    rememberDismiss("installed");
    refreshInstallButton();
  });

  document.addEventListener("DOMContentLoaded", () => {
    const button = document.querySelector("#installButton");
    if (button) button.addEventListener("click", install);
    refreshInstallButton();
  });

  window.sixEtapesPwa = { refreshInstallButton, install, standalone: STANDALONE, inApp: IN_APP };
})();
