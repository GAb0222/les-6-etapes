const APP_DATA = window.APP_BOOTSTRAP || {};
const STEPS = Array.isArray(APP_DATA.steps) ? APP_DATA.steps : [];
const ALL_CARDS = Array.isArray(APP_DATA.cards) ? APP_DATA.cards : [];
const DEFAULT_SETTINGS = APP_DATA.settings || {};
const DEFAULT_THEME = APP_DATA.theme || {};
const SESSION = APP_DATA.session || null;
const UI_COPY = {
  hintDefault: "Clique sur l'etape correspondante a la carte en cours.",
  allCardsPlaced: "Toutes les cartes sont placées !",
  ...APP_DATA.ui,
};

const STEP_EMOJIS = ["💡", "🔎", "💶", "🤝", "⚖️", "🚀", "📌", "🧭", "📊", "✅", "🧩", "🎯"];
const SCORE = {
  correct: 100,
  error: -20,
  hint: -10,
  streakBonus: 50,
  trap: 150,
  bonus: 150,
  case: 200,
};

const COMPETENCY_LABELS = {
  general: "Général",
  idea: "Idée",
  market: "Marché",
  finance: "Finance",
  funding: "Financement",
  legal: "Juridique",
  formalities: "Formalités",
};

const LEVELS = {
  easy: {
    getTitle: (card) => card.title,
    getDesc: (card) => card.desc,
  },
  normal: {
    getTitle: (card) => card.title,
    getDesc: (card) => card.desc,
  },
  expert: {
    getTitle: (card) => card.title,
    getDesc: () => "Cliquez sur l'étape correspondante.",
  },
};

const state = {
  level: "easy",
  mode: SESSION ? "classroom" : "discovery",
  settings: {
    layout: DEFAULT_SETTINGS.layout || "Grille",
    showTimer: Boolean(DEFAULT_SETTINGS.showTimer),
    showHints: DEFAULT_SETTINGS.showHints !== false,
    requireOrderFirst: DEFAULT_SETTINGS.requireOrderFirst !== false,
    enableCaseRound: DEFAULT_SETTINGS.enableCaseRound !== false,
    enableBadges: DEFAULT_SETTINGS.enableBadges !== false,
    enableTrapCards: DEFAULT_SETTINGS.enableTrapCards !== false,
    difficultyMode: DEFAULT_SETTINGS.difficultyMode || "progressive",
    accentColor: DEFAULT_THEME.primary || DEFAULT_SETTINGS.accentColor || "#1D5BD4",
  },
  cards: [],
  idx: 0,
  placements: {},
  errors: 0,
  score: 0,
  streak: 0,
  maxStreak: 0,
  masteredSteps: new Set(),
  mistakeCardIds: new Set(),
  competencyStats: {},
  earnedBadges: [],
  order: null,
  errorStepId: null,
  seconds: 0,
  done: false,
  started: false,
  completionDismissed: false,
  resultSubmitted: false,
  locked: false,
  expandedStepId: null,
};

const SAVE_KEY = "six-etapes:partie:v1";
const SAVE_MAX_AGE_MS = 24 * 60 * 60 * 1000;
const IN_APP = /SixEtapesApp/i.test(navigator.userAgent);

let timerId = null;
let toastTimerId = null;
let pointerDrag = null;
let hoveredDropTarget = null;
let progressTimerId = null;
let progressInFlight = false;
let latestProgressPayload = null;

function escapeHtml(value) {
  return String(value)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

const els = {
  progressFill: document.querySelector("#progressFill"),
  progressCount: document.querySelector("#progressCount"),
  errorCount: document.querySelector("#errorCount"),
  timerText: document.querySelector("#timerText"),
  scoreText: document.querySelector("#scoreText"),
  streakText: document.querySelector("#streakText"),
  masteryText: document.querySelector("#masteryText"),
  activeCardShell: document.querySelector("#activeCardShell"),
  miniProgressList: document.querySelector("#miniProgressList"),
  hintText: document.querySelector("#hintText"),
  stepsGrid: document.querySelector("#stepsGrid"),
  hintButton: document.querySelector("#hintButton"),
  learningText: document.querySelector("#learningText"),
  skipCardButton: document.querySelector("#skipCardButton"),
  resetButton: document.querySelector("#resetButton"),
  playAgainButton: document.querySelector("#playAgainButton"),
  playSoloButton: document.querySelector("#playSoloButton"),
  completionScreen: document.querySelector("#completionScreen"),
  closeCompletionButton: document.querySelector("#closeCompletionButton"),
  completionTitle: document.querySelector("#completionTitle"),
  completionSummary: document.querySelector("#completionSummary"),
  completionCards: document.querySelector("#completionCards"),
  completionMistakes: document.querySelector("#completionMistakes"),
  completionTime: document.querySelector("#completionTime"),
  completionScore: document.querySelector("#completionScore"),
  completionMastery: document.querySelector("#completionMastery"),
  completionReview: document.querySelector("#completionReview"),
  correctionEmailForm: document.querySelector("#correctionEmailForm"),
  correctionEmail: document.querySelector("#correctionEmail"),
  correctionEmailStatus: document.querySelector("#correctionEmailStatus"),
  replayErrorsButton: document.querySelector("#replayErrorsButton"),
  starsRow: document.querySelector("#starsRow"),
  modePill: document.querySelector("#modePill"),
  modeButtons: [...document.querySelectorAll(".mode-choice-button")],
  orderPanel: document.querySelector("#orderPanel"),
  orderPool: document.querySelector("#orderPool"),
  orderSlots: document.querySelector("#orderSlots"),
  orderFeedback: document.querySelector("#orderFeedback"),
  orderValidateButton: document.querySelector("#orderValidateButton"),
  orderResetButton: document.querySelector("#orderResetButton"),
  introScreen: document.querySelector("#introScreen"),
  startIntroButton: document.querySelector("#startIntroButton"),
  toast: document.querySelector("#feedbackToast"),
};

function isCompactScreen() {
  return window.innerWidth < 768;
}

// Retour haptique : natif via l'app iOS (WKWebView), sinon Vibration API (Android).
function haptic(kind) {
  try {
    const bridge = window.webkit?.messageHandlers?.haptic;
    if (bridge) {
      bridge.postMessage(kind);
      return;
    }
    if (typeof navigator.vibrate === "function") {
      navigator.vibrate(kind === "error" ? [40, 60, 40] : kind === "success" ? [18] : [8]);
    }
  } catch {
    // Pas de retour haptique disponible : on ignore.
  }
}

function showToast(text, tone = "", duration = null) {
  if (!els.toast || !text) return;
  els.toast.textContent = text;
  els.toast.className = `feedback-toast is-visible ${tone}`.trim();
  window.clearTimeout(toastTimerId);
  toastTimerId = window.setTimeout(() => {
    els.toast.classList.remove("is-visible");
  }, duration ?? (tone === "error" ? 3600 : 2200));
}

function hideToast() {
  if (!els.toast) return;
  window.clearTimeout(toastTimerId);
  els.toast.classList.remove("is-visible");
}

// Sauvegarde locale : un rechargement (ou un retour dans l'app) ne perd pas la partie.
function saveState() {
  if (SESSION || !state.started || state.done) return;
  try {
    const snapshot = {
      v: 1,
      savedAt: Date.now(),
      mode: state.mode,
      level: state.level,
      cardIds: state.cards.map((card) => card.id),
      idx: state.idx,
      placements: Object.fromEntries(Object.entries(state.placements).map(([key, card]) => [key, card.id])),
      errors: state.errors,
      score: state.score,
      streak: state.streak,
      maxStreak: state.maxStreak,
      masteredSteps: [...state.masteredSteps],
      mistakeCardIds: [...state.mistakeCardIds],
      competencyStats: state.competencyStats,
      seconds: state.seconds,
      order: state.order
        ? {
          pool: state.order.pool,
          slots: state.order.slots,
          solved: state.order.solved,
          validated: state.order.validated,
          scoreAwarded: state.order.scoreAwarded,
        }
        : null,
    };
    window.localStorage.setItem(SAVE_KEY, JSON.stringify(snapshot));
  } catch {
    // Stockage indisponible (navigation privée, quota) : on continue sans sauvegarde.
  }
}

function clearSavedState() {
  try {
    window.localStorage.removeItem(SAVE_KEY);
  } catch {
    // Ignore.
  }
}

function restoreState() {
  if (SESSION) return false;
  let saved = null;
  try {
    saved = JSON.parse(window.localStorage.getItem(SAVE_KEY) || "null");
  } catch {
    return false;
  }
  if (!saved || saved.v !== 1 || !Array.isArray(saved.cardIds) || !saved.cardIds.length) return false;
  if (Date.now() - Number(saved.savedAt || 0) > SAVE_MAX_AGE_MS) {
    clearSavedState();
    return false;
  }

  const byId = new Map(ALL_CARDS.map((card) => [card.id, card]));
  const cards = saved.cardIds.map((id) => byId.get(id)).filter(Boolean);
  // Le contenu du jeu a changé depuis la sauvegarde : on repart proprement.
  if (cards.length !== ALL_CARDS.length || saved.idx >= cards.length) {
    clearSavedState();
    return false;
  }

  state.cards = cards;
  state.idx = Number(saved.idx) || 0;
  state.placements = {};
  Object.entries(saved.placements || {}).forEach(([key, id]) => {
    const card = byId.get(id);
    if (card) state.placements[key] = card;
  });
  state.errors = Number(saved.errors) || 0;
  state.score = Number(saved.score) || 0;
  state.streak = Number(saved.streak) || 0;
  state.maxStreak = Number(saved.maxStreak) || 0;
  state.masteredSteps = new Set(saved.masteredSteps || []);
  state.mistakeCardIds = new Set(saved.mistakeCardIds || []);
  state.competencyStats = saved.competencyStats || {};
  state.earnedBadges = [];
  initOrderGame();
  if (saved.order && Array.isArray(saved.order.slots) && saved.order.slots.length === STEPS.length) {
    state.order.pool = saved.order.pool.filter((id) => getStepById(id));
    state.order.slots = saved.order.slots;
    state.order.solved = Boolean(saved.order.solved) || state.order.solved;
    state.order.validated = Boolean(saved.order.validated);
    state.order.scoreAwarded = Boolean(saved.order.scoreAwarded);
    if (state.order.solved) {
      state.order.feedback = "Ordre validé : classe maintenant les cartes dans les bonnes étapes.";
      state.order.tone = "success";
    }
  }
  state.errorStepId = null;
  state.done = false;
  state.completionDismissed = false;
  state.resultSubmitted = false;
  state.started = true;
  document.body.classList.remove("intro-active");
  if (els.introScreen) els.introScreen.hidden = true;
  setMode(saved.mode === "challenge" ? "challenge" : "discovery");
  if (saved.level && LEVELS[saved.level]) state.level = saved.level;
  restartTimer(Number(saved.seconds) || 0);
  render();
  showToast("Partie reprise là où tu t'étais arrêté.", "", 2600);
  return true;
}

function shuffle(items) {
  const copy = [...items];
  for (let index = copy.length - 1; index > 0; index -= 1) {
    const swapIndex = Math.floor(Math.random() * (index + 1));
    [copy[index], copy[swapIndex]] = [copy[swapIndex], copy[index]];
  }
  return copy;
}

function formatTime(totalSeconds) {
  const minutes = Math.floor(totalSeconds / 60);
  const seconds = totalSeconds % 60;
  return `${String(minutes).padStart(2, "0")}:${String(seconds).padStart(2, "0")}`;
}

function currentCard() {
  return state.idx < state.cards.length ? state.cards[state.idx] : null;
}

function filledSlots(stepId) {
  return Object.keys(state.placements).filter((key) => key.startsWith(`${stepId}_`)).length;
}

function stepCardCount(stepId) {
  return ALL_CARDS.filter((card) => card.stepId === stepId).length || 1;
}

function isComplete(stepId) {
  return filledSlots(stepId) >= stepCardCount(stepId);
}

function restartTimer(fromSeconds = 0) {
  window.clearInterval(timerId);
  state.seconds = fromSeconds;
  syncTimer();
  if (!state.started || !shouldRunTimer() || state.done) return;
  timerId = window.setInterval(() => {
    state.seconds += 1;
    syncTimer();
    if (state.seconds % 5 === 0) saveState();
  }, 1000);
}

function syncTimer() {
  if (!els.timerText) return;
  els.timerText.hidden = !shouldShowTimer();
  els.timerText.textContent = formatTime(state.seconds);
}

function shouldShowTimer() {
  if (SESSION && SESSION.ambiance === "calme") return false;
  return state.settings.showTimer || state.mode === "challenge" || state.mode === "classroom";
}

function shouldRunTimer() {
  return state.mode === "challenge" || state.mode === "classroom" || state.settings.showTimer;
}

function hexToRgb(hex) {
  const match = String(hex).match(/^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i);
  if (!match) return null;
  return {
    r: Number.parseInt(match[1], 16),
    g: Number.parseInt(match[2], 16),
    b: Number.parseInt(match[3], 16),
  };
}

function withAlpha(hex, alpha) {
  const rgb = hexToRgb(hex);
  if (!rgb) return `rgba(29, 91, 212, ${alpha})`;
  return `rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, ${alpha})`;
}

function applyTheme() {
  const root = document.documentElement;
  const theme = {
    primary: DEFAULT_THEME.primary || state.settings.accentColor,
    secondary: DEFAULT_THEME.secondary || "#34D399",
    accent: DEFAULT_THEME.accent || "#F59E0B",
    background: DEFAULT_THEME.background || "#EEF3FB",
    surface: DEFAULT_THEME.surface || "#FFFFFF",
    text: DEFAULT_THEME.text || "#1A1F36",
    muted: DEFAULT_THEME.muted || "#6B7280",
  };

  state.settings.accentColor = theme.primary;
  root.style.setProperty("--accent", theme.primary);
  root.style.setProperty("--brand-secondary", theme.secondary);
  root.style.setProperty("--brand-accent", theme.accent);
  root.style.setProperty("--bg", theme.background);
  root.style.setProperty("--paper", theme.surface);
  root.style.setProperty("--ink", theme.text);
  root.style.setProperty("--muted", theme.muted);
  root.style.setProperty("--accent-soft", withAlpha(theme.primary, 0.12));
  root.style.setProperty("--accent-glow", withAlpha(theme.primary, 0.18));
}

function playSoloAgain() {
  clearSavedState();
  window.location.href = "index.php";
}

function resetGame() {
  clearSavedState();
  hideToast();
  state.cards = shuffle(ALL_CARDS);
  state.idx = 0;
  state.placements = {};
  state.errors = 0;
  state.score = 0;
  state.streak = 0;
  state.maxStreak = 0;
  state.masteredSteps = new Set();
  state.mistakeCardIds = new Set();
  state.competencyStats = {};
  state.earnedBadges = [];
  initOrderGame();
  state.errorStepId = null;
  state.locked = false;
  state.expandedStepId = null;
  state.done = false;
  state.started = state.started && !document.body.classList.contains("intro-active");
  state.completionDismissed = false;
  state.resultSubmitted = false;
  if (els.completionScreen) {
    els.completionScreen.hidden = true;
    els.completionScreen.classList.remove("is-closed");
    els.completionScreen.style.display = "";
  }
  restartTimer();
  render();
}

// Bouton "Recommencer" en cours de partie : on demande confirmation pour éviter
// de perdre une progression d'un tap malheureux (fréquent sur mobile).
function confirmResetGame() {
  const hasProgress = state.started && !state.done
    && (state.idx > 0 || (state.order && state.order.slots.some((stepId) => stepId !== null)));
  if (hasProgress && !window.confirm("Recommencer la partie ? Ta progression actuelle sera perdue.")) {
    return;
  }
  resetGame();
}

function startIntro() {
  state.started = true;
  document.body.classList.remove("intro-active");
  if (els.introScreen) {
    els.introScreen.hidden = true;
  }
  restartTimer();
  queueSessionProgress("order", "Début de partie");
  render();
}

function initOrderGame() {
  state.order = {
    pool: shuffle(STEPS.map((step) => step.id)),
    slots: Array(STEPS.length).fill(null),
    selectedId: null,
    validated: false,
    solved: !state.settings.requireOrderFirst || STEPS.length <= 1,
    scoreAwarded: false,
    feedback: "Objectif : remettre les étapes, puis les sous-étapes, dans le bon ordre logique.",
    tone: "",
  };
}

function setMode(mode) {
  state.mode = mode;
  state.level = mode === "challenge" || mode === "classroom" ? "normal" : "easy";
  if (state.settings.difficultyMode === "discovery") state.level = "easy";
  if (state.settings.difficultyMode === "challenge") state.level = "normal";
  if (state.settings.difficultyMode === "expert") state.level = "expert";
  if (els.modePill) {
    els.modePill.textContent = mode === "classroom"
      ? (SESSION && SESSION.ambiance === "calme" ? "Séance calme" : "Séance")
      : mode === "challenge" ? "Challenge" : "Découverte";
  }
  els.modeButtons.forEach((button) => {
    button.classList.toggle("active", button.dataset.mode === mode);
  });
  restartTimer();
  renderTopbar();
}

function updateScore(points) {
  state.score = Math.max(0, state.score + points);
}

function cardType(card) {
  const type = card?.type || "simple";
  if (type === "trap" && !state.settings.enableTrapCards) return "simple";
  if (type === "case" && !state.settings.enableCaseRound) return "simple";
  return ["simple", "trap", "bonus", "case"].includes(type) ? type : "simple";
}

function cardPoints(card) {
  const type = cardType(card);
  if (type === "trap") return SCORE.trap;
  if (type === "bonus") return SCORE.bonus;
  if (type === "case") return SCORE.case;
  return SCORE.correct;
}

function cardPrompt(card) {
  if (cardType(card) === "case" && card.casePrompt) {
    return card.casePrompt;
  }
  return card.desc || "";
}

function cardSuccessExplanation(card) {
  return card.explanation || card.desc || "Cette action appartient à cette étape du parcours.";
}

function cardErrorExplanation(card, expectedStep) {
  if (card.errorExplanation) {
    return card.errorExplanation;
  }
  return `Cette carte relève plutôt de “${expectedStep ? expectedStep.title : "l’étape attendue"}” : compare le verbe d’action avec l’objectif de l’étape.`;
}

function trackCompetency(card, success) {
  const key = card.competency || "general";
  if (!state.competencyStats[key]) {
    state.competencyStats[key] = { success: 0, errors: 0 };
  }
  state.competencyStats[key][success ? "success" : "errors"] += 1;
}

function computeBadges() {
  const badges = [];
  if (state.maxStreak >= 5) badges.push({ title: "Stratège", text: "Série de 5 bonnes réponses." });
  if (state.errors <= 2) badges.push({ title: "Décideur précis", text: "Moins de 3 erreurs." });
  Object.entries(state.competencyStats).forEach(([key, stats]) => {
    if (stats.success > 0 && stats.errors === 0 && key !== "general") {
      badges.push({ title: COMPETENCY_LABELS[key] || key, text: "Compétence validée sans erreur." });
    }
  });
  return badges.slice(0, 6);
}

function setLearningText(text) {
  if (!els.learningText) return;
  els.learningText.textContent = text;
}

function renderTopbar() {
  const placed = Math.min(state.idx, state.cards.length);
  const percent = state.cards.length ? (placed / state.cards.length) * 100 : 0;
  els.progressFill.style.width = `${percent}%`;
  els.progressCount.textContent = `${placed}/${state.cards.length}`;
  els.errorCount.textContent = String(state.errors);
  els.errorCount.classList.toggle("has-errors", state.errors > 0);
  if (els.scoreText) {
    els.scoreText.textContent = `${state.score} pts`;
  }
  if (els.streakText) {
    els.streakText.textContent = `Série ${state.streak}`;
  }
  if (els.masteryText) {
    els.masteryText.textContent = `${state.masteredSteps.size}/${STEPS.length}`;
  }
  syncTimer();
}

function getStepById(stepId) {
  return STEPS.find((step) => step.id === stepId) || null;
}

function orderStepLabel(stepId) {
  const step = getStepById(stepId);
  return step ? (step.short || step.title) : "";
}

function firstEmptyOrderSlot() {
  return state.order.slots.findIndex((stepId) => stepId === null);
}

function removeStepFromOrder(stepId) {
  state.order.pool = state.order.pool.filter((id) => id !== stepId);
  state.order.slots = state.order.slots.map((id) => (id === stepId ? null : id));
}

function placeOrderStep(stepId, slotIndex = firstEmptyOrderSlot()) {
  if (!state.order || state.order.solved || slotIndex < 0 || slotIndex >= state.order.slots.length) return;

  const previous = state.order.slots[slotIndex];
  removeStepFromOrder(stepId);
  if (previous !== null && previous !== stepId) {
    state.order.pool.push(previous);
  }
  state.order.slots[slotIndex] = stepId;
  state.order.selectedId = null;
  state.order.validated = false;
  const remaining = state.order.slots.filter((id) => id === null).length;
  state.order.feedback = remaining === 0
    ? "Tout est placé : appuie sur « Valider l'ordre »."
    : `Encore ${remaining} étape${remaining > 1 ? "s" : ""} à placer, puis valide l'ordre.`;
  state.order.tone = "";
  haptic("light");
  renderOrderGame();
  saveState();
  queueSessionProgress("order", `Étape placée : ${orderStepLabel(stepId)}`);
}

function returnOrderStep(stepId) {
  if (!state.order || state.order.solved) return;
  removeStepFromOrder(stepId);
  state.order.pool.push(stepId);
  state.order.selectedId = null;
  state.order.validated = false;
  state.order.feedback = "Carte remise dans la pioche. Replace-la au bon numéro.";
  state.order.tone = "";
  renderOrderGame();
  saveState();
  queueSessionProgress("order", `Étape retirée : ${orderStepLabel(stepId)}`);
}

function selectOrderStep(stepId) {
  if (!state.order || state.order.solved) return;
  state.order.selectedId = state.order.selectedId === stepId ? null : stepId;
  state.order.feedback = state.order.selectedId
    ? `Carte sélectionnée : ${orderStepLabel(stepId)}. Clique un numéro pour la placer.`
    : "Sélection annulée.";
  state.order.tone = "";
  renderOrderGame();
}

function validateOrderGame() {
  if (!state.order || state.order.solved) return;

  if (state.order.slots.some((stepId) => stepId === null)) {
    const missing = state.order.slots.filter((stepId) => stepId === null).length;
    state.order.feedback = `Il manque encore ${missing} étape${missing > 1 ? "s" : ""} : remplis tous les emplacements avant de valider.`;
    state.order.tone = "error";
    haptic("error");
    renderOrderGame();
    return;
  }

  state.order.validated = true;
  const solved = state.order.slots.every((stepId, index) => stepId === STEPS[index].id);
  if (solved) {
    state.order.solved = true;
    state.order.feedback = "Ordre validé : tu peux maintenant classer les cartes dans les bonnes étapes.";
    state.order.tone = "success";
    if (!state.order.scoreAwarded) {
      updateScore(150);
      state.order.scoreAwarded = true;
    }
    haptic("success");
    showToast("Ordre validé, +150 pts. À toi de classer les cartes !", "success");
    setLearningText("Tu as posé le parcours global : maintenant associe chaque action au bon moment.");
    queueSessionProgress("cards", "Ordre des étapes validé");
    // Sur mobile, on remonte en haut pour découvrir la carte active.
    if (isCompactScreen()) {
      window.setTimeout(() => window.scrollTo({ top: 0, behavior: "smooth" }), 50);
    }
  } else {
    const returnedStepIds = [];
    state.order.slots = state.order.slots.map((stepId, index) => {
      if (stepId === STEPS[index].id) {
        return stepId;
      }
      returnedStepIds.push(stepId);
      return null;
    });
    returnedStepIds.forEach((stepId) => {
      if (stepId !== null && !state.order.pool.includes(stepId)) {
        state.order.pool.push(stepId);
      }
    });
    state.errors += 1;
    state.streak = 0;
    updateScore(SCORE.error);
    const kept = state.order.slots.filter((stepId) => stepId !== null).length;
    state.order.feedback = kept > 0
      ? `Pas encore : ${kept} étape${kept > 1 ? "s" : ""} bien placée${kept > 1 ? "s" : ""} (en vert), les autres sont revenues dans la pioche.`
      : "Pas encore : aucune étape au bon endroit. Réfléchis au sens du parcours : idée → marché → chiffres → financement → juridique → création.";
    state.order.tone = "error";
    haptic("error");
    queueSessionProgress("order", "Ordre à retravailler");
  }
  renderTopbar();
  renderOrderGame();
  saveState();
}

function resetOrderGame() {
  initOrderGame();
  renderOrderGame();
  setLearningText(`Commence par retrouver l'ordre logique des ${STEPS.length} étapes.`);
}

function handleOrderDragStart(event) {
  event.dataTransfer.effectAllowed = "move";
  event.dataTransfer.setData("text/plain", event.currentTarget.dataset.stepId);
}

function handleOrderDragOver(event) {
  event.preventDefault();
  event.dataTransfer.dropEffect = "move";
}

function handleOrderDrop(event) {
  event.preventDefault();
  const stepId = Number(event.dataTransfer.getData("text/plain"));
  const slotIndex = Number(event.currentTarget.dataset.slotIndex);
  if (stepId) {
    placeOrderStep(stepId, slotIndex);
  }
}

function renderOrderGame() {
  if (!els.orderPanel || !state.order) return;

  els.orderPanel.classList.toggle("is-complete", state.order.solved);
  els.orderPool.innerHTML = "";
  els.orderSlots.innerHTML = "";

  state.order.pool.forEach((stepId) => {
    const chip = document.createElement("div");
    chip.className = `order-chip ${state.order.selectedId === stepId ? "is-selected" : ""}`.trim();
    chip.draggable = !state.order.solved;
    chip.dataset.stepId = String(stepId);
    chip.innerHTML = `
      <span>${escapeHtml(orderStepLabel(stepId))}</span>
    `;
    chip.addEventListener("click", () => {
      const emptyIndex = firstEmptyOrderSlot();
      if (emptyIndex >= 0) {
        placeOrderStep(stepId, emptyIndex);
      } else {
        selectOrderStep(stepId);
      }
    });
    chip.addEventListener("dragstart", handleOrderDragStart);
    els.orderPool.appendChild(chip);
  });

  state.order.slots.forEach((stepId, index) => {
    const slot = document.createElement("div");
    const isCorrect = state.order.validated && stepId === STEPS[index].id;
    const isWrong = state.order.validated && stepId !== null && stepId !== STEPS[index].id;
    slot.className = `order-slot ${stepId ? "filled" : ""} ${isCorrect ? "correct" : ""} ${isWrong ? "wrong" : ""}`.trim();
    slot.dataset.slotIndex = String(index);
    slot.innerHTML = `
      <span class="order-slot-index">${index + 1}</span>
      <span class="order-slot-text">${stepId ? escapeHtml(orderStepLabel(stepId)) : "Déposer l'étape ici"}</span>
    `;
    slot.addEventListener("dragover", handleOrderDragOver);
    slot.addEventListener("drop", handleOrderDrop);
    slot.addEventListener("click", () => {
      if (state.order.solved) return;
      if (state.order.selectedId !== null) {
        placeOrderStep(state.order.selectedId, index);
      } else if (stepId !== null) {
        returnOrderStep(stepId);
      }
    });
    els.orderSlots.appendChild(slot);
  });

  els.orderFeedback.textContent = state.order.feedback;
  els.orderFeedback.className = `order-feedback ${state.order.tone}`.trim();
  document.body.classList.toggle("order-locked", state.settings.requireOrderFirst && !state.order.solved);
  if (els.orderValidateButton) {
    els.orderValidateButton.disabled = state.order.solved;
    els.orderValidateButton.textContent = state.order.solved ? "Ordre validé" : "Valider l'ordre";
  }
}

function renderActiveCard() {
  const card = currentCard();
  if (!card) {
    els.activeCardShell.innerHTML = `<div class="empty-finish">${escapeHtml(UI_COPY.allCardsPlaced)}</div>`;
    return;
  }

  const level = LEVELS[state.level];
  const type = cardType(card);
  const typeLabel = type === "trap" ? "Piège" : type === "bonus" ? "Bonus" : type === "case" ? "Cas pratique" : "Carte";
  const compact = isCompactScreen();
  const callout = state.order && !state.order.solved
    ? `Commence par remettre les ${STEPS.length} étapes dans le bon ordre`
    : compact ? "Touche l'étape qui correspond à cette carte" : "Glissez la carte vers une étape ou cliquez sur l'étape correspondante";
  els.activeCardShell.innerHTML = `
    <article class="active-card" draggable="true" aria-grabbed="false">
      <div class="card-badge">${typeLabel} ${state.idx + 1} / ${state.cards.length}</div>
      <h2 class="card-title">${escapeHtml(level.getTitle(card))}</h2>
      <p class="card-desc">${escapeHtml(type === "case" ? cardPrompt(card) : level.getDesc(card))}</p>
      <div class="card-callout">
        <svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true">
          <path d="M7 1v12M1 7h12" stroke="${state.settings.accentColor}" stroke-width="1.8" stroke-linecap="round" opacity="0.5"></path>
        </svg>
        <span>${callout}</span>
      </div>
    </article>
  `;
  bindActiveCardDrag();
}

function renderMiniProgress() {
  els.miniProgressList.innerHTML = "";
  STEPS.forEach((step, index) => {
    const filled = filledSlots(step.id);
    const total = stepCardCount(step.id);
    const color = step.color || ["#1D5BD4", "#6D28D9", "#059669", "#D97706", "#DC2626", "#0891B2"][index % 6];
    const row = document.createElement("div");
    row.className = `mini-progress-item${filled >= total ? " is-complete" : ""}`;
    row.style.setProperty("--step-color", color);
    row.innerHTML = `
      <span class="mini-progress-index">${index + 1}</span>
      <div class="mini-progress-bar">
        <span style="width:${Math.min(100, (filled / total) * 100)}%"></span>
      </div>
      <span class="mini-progress-count">${filled}/${total}</span>
    `;
    els.miniProgressList.appendChild(row);
  });
}

function slotMarkup(card, color, compact, slotIndex) {
  if (!card) {
    return `
      <div class="slot empty ${compact ? "compact" : ""}">
        <span class="slot-number">${slotIndex + 1}</span>
        <span class="slot-text">Emplacement ${slotIndex + 1}</span>
      </div>
    `;
  }

  return `
    <div class="slot filled ${compact ? "compact" : ""}">
      <span class="slot-check" aria-hidden="true"></span>
      <span class="slot-text">${escapeHtml(card.title)}</span>
    </div>
  `;
}

function renderSteps() {
  const mobile = isCompactScreen();
  const compact = mobile || state.settings.layout === "Colonnes";
  const layoutClass = state.settings.layout === "Colonnes"
    ? "layout-colonnes"
    : state.settings.layout === "Liste"
      ? "layout-liste"
      : "";

  const countClass = `steps-count-${Math.min(STEPS.length, 12)}`;
  els.stepsGrid.className = `steps-grid ${layoutClass} ${countClass}`.trim();
  els.stepsGrid.innerHTML = "";

  STEPS.forEach((step, index) => {
    const color = step.color || ["#1D5BD4", "#6D28D9", "#059669", "#D97706", "#DC2626", "#0891B2"][index % 6];
    const complete = isComplete(step.id);
    const clickable = !complete && Boolean(currentCard());
    const expanded = mobile && state.expandedStepId === step.id;
    const card = document.createElement("article");
    card.className = `step-card ${compact ? "compact" : ""} ${complete ? "complete" : ""} ${clickable ? "clickable droppable" : ""} ${state.errorStepId === step.id ? "error" : ""} ${expanded ? "is-expanded" : ""}`.trim();
    card.dataset.stepId = String(step.id);
    card.style.setProperty("--step-color", color);
    if (clickable) card.setAttribute("role", "button");

    if (clickable) {
      card.addEventListener("click", () => place(step.id));
      card.addEventListener("dragenter", handleStepDragEnter);
      card.addEventListener("dragover", handleStepDragOver);
      card.addEventListener("dragleave", handleStepDragLeave);
      card.addEventListener("drop", handleStepDrop);
    } else if (mobile && complete) {
      // Sur mobile, une étape terminée est repliée : un tap montre les cartes placées.
      card.addEventListener("click", () => {
        state.expandedStepId = state.expandedStepId === step.id ? null : step.id;
        renderSteps();
      });
    }

    const slotCount = stepCardCount(step.id);
    const slots = Array.from({ length: slotCount }, (_, slotIndex) => state.placements[`${step.id}_${slotIndex}`] || null);
    card.innerHTML = `
      <div class="step-card-header">
        <span class="step-index">${complete ? "✓" : index + 1}</span>
        <span class="step-title">${escapeHtml(step.title)}</span>
        <span class="step-count">${slots.filter(Boolean).length}/${slotCount}</span>
      </div>
      <div class="step-card-body">
        ${slots.map((slotCard, slotIndex) => slotMarkup(slotCard, color, compact, slotIndex)).join("")}
      </div>
    `;

    els.stepsGrid.appendChild(card);
  });
}

function bindActiveCardDrag() {
  const activeCard = els.activeCardShell.querySelector(".active-card");
  if (!activeCard) return;

  activeCard.addEventListener("dragstart", handleCardDragStart);
  activeCard.addEventListener("dragend", clearDragState);
  activeCard.addEventListener("pointerdown", handlePointerDragStart);
}

function handleCardDragStart(event) {
  if (!currentCard()) {
    event.preventDefault();
    return;
  }

  event.dataTransfer.effectAllowed = "move";
  event.dataTransfer.setData("text/plain", String(currentCard().id));
  document.body.classList.add("is-dragging-card");
  event.currentTarget.setAttribute("aria-grabbed", "true");
}

function handleStepDragEnter(event) {
  event.preventDefault();
  markDropTarget(event.currentTarget);
}

function handleStepDragOver(event) {
  event.preventDefault();
  event.dataTransfer.dropEffect = "move";
  markDropTarget(event.currentTarget);
}

function handleStepDragLeave(event) {
  if (!event.currentTarget.contains(event.relatedTarget)) {
    event.currentTarget.classList.remove("drag-over");
  }
}

function handleStepDrop(event) {
  event.preventDefault();
  const stepId = Number(event.currentTarget.dataset.stepId);
  clearDragState();
  place(stepId);
}

function markDropTarget(target) {
  if (hoveredDropTarget && hoveredDropTarget !== target) {
    hoveredDropTarget.classList.remove("drag-over");
  }
  hoveredDropTarget = target;
  target.classList.add("drag-over");
}

function clearDragState() {
  document.body.classList.remove("is-dragging-card", "is-touch-dragging");
  document.querySelectorAll(".step-card.drag-over").forEach((item) => item.classList.remove("drag-over"));
  const activeCard = els.activeCardShell.querySelector(".active-card");
  if (activeCard) {
    activeCard.setAttribute("aria-grabbed", "false");
  }
  if (pointerDrag?.ghost) {
    pointerDrag.ghost.remove();
  }
  hoveredDropTarget = null;
  pointerDrag = null;
}

function handlePointerDragStart(event) {
  if (!currentCard() || event.button !== 0) return;

  pointerDrag = {
    pointerId: event.pointerId,
    originX: event.clientX,
    originY: event.clientY,
    lastX: event.clientX,
    lastY: event.clientY,
    source: event.currentTarget,
    ghost: null,
    dragging: false,
  };

  event.currentTarget.setPointerCapture(event.pointerId);
  event.currentTarget.addEventListener("pointermove", handlePointerDragMove);
  event.currentTarget.addEventListener("pointerup", handlePointerDragEnd);
  event.currentTarget.addEventListener("pointercancel", handlePointerDragCancel);
}

function handlePointerDragMove(event) {
  if (!pointerDrag || pointerDrag.pointerId !== event.pointerId) return;

  const distance = Math.hypot(event.clientX - pointerDrag.originX, event.clientY - pointerDrag.originY);
  if (!pointerDrag.dragging && distance < 8) return;

  if (!pointerDrag.dragging) {
    pointerDrag.dragging = true;
    pointerDrag.ghost = pointerDrag.source.cloneNode(true);
    pointerDrag.ghost.classList.add("drag-ghost");
    pointerDrag.ghost.removeAttribute("draggable");
    document.body.appendChild(pointerDrag.ghost);
    document.body.classList.add("is-dragging-card", "is-touch-dragging");
    pointerDrag.source.setAttribute("aria-grabbed", "true");
  }

  event.preventDefault();
  pointerDrag.lastX = event.clientX;
  pointerDrag.lastY = event.clientY;
  pointerDrag.ghost.style.transform = `translate(${event.clientX}px, ${event.clientY}px) translate(-50%, -50%)`;

  pointerDrag.ghost.hidden = true;
  const target = document.elementFromPoint(event.clientX, event.clientY)?.closest(".step-card.droppable");
  pointerDrag.ghost.hidden = false;

  if (target) {
    markDropTarget(target);
  } else if (hoveredDropTarget) {
    hoveredDropTarget.classList.remove("drag-over");
    hoveredDropTarget = null;
  }
}

function handlePointerDragEnd(event) {
  if (!pointerDrag || pointerDrag.pointerId !== event.pointerId) return;

  const shouldDrop = pointerDrag.dragging;
  const target = shouldDrop
    ? document.elementFromPoint(pointerDrag.lastX, pointerDrag.lastY)?.closest(".step-card.droppable")
    : null;

  pointerDrag.source.releasePointerCapture(event.pointerId);
  pointerDrag.source.removeEventListener("pointermove", handlePointerDragMove);
  pointerDrag.source.removeEventListener("pointerup", handlePointerDragEnd);
  pointerDrag.source.removeEventListener("pointercancel", handlePointerDragCancel);
  clearDragState();

  if (target) {
    place(Number(target.dataset.stepId));
  }
}

function handlePointerDragCancel(event) {
  if (!pointerDrag || pointerDrag.pointerId !== event.pointerId) return;

  pointerDrag.source.releasePointerCapture(event.pointerId);
  pointerDrag.source.removeEventListener("pointermove", handlePointerDragMove);
  pointerDrag.source.removeEventListener("pointerup", handlePointerDragEnd);
  pointerDrag.source.removeEventListener("pointercancel", handlePointerDragCancel);
  clearDragState();
}

function renderHint() {
  const card = currentCard();
  if (!card) {
    els.hintText.textContent = "Toutes les cartes sont classées. Regarde ton résultat final.";
    return;
  }
  if (!state.settings.showHints) {
    els.hintText.textContent = "Lis la carte, repère l’action principale, puis associe-la à l’étape logique du parcours.";
    return;
  }
  if (state.settings.requireOrderFirst && state.order && !state.order.solved) {
    els.hintText.textContent = `Commence par reconstruire l'ordre des ${STEPS.length} étapes : cela t'aidera ensuite à classer les cartes.`;
    return;
  }
  const step = STEPS.find((item) => item.id === card.stepId);
  els.hintText.textContent = `Carte ${state.idx + 1}/${state.cards.length} : cherche l'étape "${step ? step.title : ""}".`;
}

function renderCompletion() {
  if (!state.done || state.completionDismissed) {
    els.completionScreen.hidden = true;
    els.completionScreen.classList.add("is-closed");
    return;
  }

  const stars = state.errors === 0 ? 3 : state.errors <= 4 ? 2 : 1;
  const title = stars === 3 ? "Parfait !" : stars === 2 ? "Bien joué !" : "Bravo, continue !";
  els.completionTitle.textContent = title;
  els.completionSummary.textContent = state.errors === 0
    ? `Tu as classé les ${state.cards.length} cartes sans aucune erreur.`
    : `Tu as classé les ${state.cards.length} cartes avec ${state.errors} erreur${state.errors > 1 ? "s" : ""}.`;
  if (els.completionCards) {
    els.completionCards.textContent = `${state.cards.length}/${state.cards.length}`;
  }
  els.completionMistakes.textContent = String(state.errors);
  els.completionTime.textContent = formatTime(state.seconds);
  if (els.completionScore) {
    els.completionScore.textContent = String(state.score);
  }
  if (els.completionMastery) {
    els.completionMastery.textContent = `${state.masteredSteps.size}/${STEPS.length}`;
  }
  if (els.correctionEmailStatus) {
    els.correctionEmailStatus.textContent = "";
    els.correctionEmailStatus.className = "completion-email-status";
  }
  renderCompletionReview();
  els.starsRow.innerHTML = "";
  for (let index = 1; index <= 3; index += 1) {
    const star = document.createElement("span");
    star.className = `star${index <= stars ? " filled" : ""}`;
    star.style.animation = index <= stars ? `star-pop 0.45s ${index * 0.12}s cubic-bezier(0.34,1.56,0.64,1) both` : "none";
    star.textContent = "⭐";
    els.starsRow.appendChild(star);
  }
  els.completionScreen.classList.remove("is-closed");
  els.completionScreen.style.display = "flex";
  els.completionScreen.hidden = false;
  // Proposition d'installation (PWA) : uniquement ici, après une partie jouée.
  if (window.sixEtapesPwa) window.sixEtapesPwa.refreshInstallButton();
}

async function sendCorrectionEmail(event) {
  event.preventDefault();
  if (!els.correctionEmail || !els.correctionEmailStatus) return;

  const email = els.correctionEmail.value.trim();
  if (!email) return;

  const button = els.correctionEmailForm.querySelector("button");
  if (button) button.disabled = true;
  els.correctionEmailStatus.textContent = "Envoi en cours...";
  els.correctionEmailStatus.className = "completion-email-status";

  try {
    const response = await fetch("email_correction.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        email,
        score: state.score,
        seconds: state.seconds,
        errors: state.errors,
        cards: state.cards.length,
        mistakes: [...state.mistakeCardIds],
      }),
    });
    const payload = await response.json();
    if (!payload.ok) {
      throw new Error(payload.error || "Envoi impossible.");
    }
    if (payload.delivered === false && payload.mailto) {
      const link = document.createElement("a");
      link.href = payload.mailto;
      link.textContent = "Ouvrir le brouillon mail";
      link.className = "completion-email-link";
      els.correctionEmailStatus.textContent = payload.message || "Le mail automatique est indisponible.";
      els.correctionEmailStatus.append(" ");
      els.correctionEmailStatus.appendChild(link);
      els.correctionEmailStatus.className = "completion-email-status error";
      return;
    }
    els.correctionEmailStatus.textContent = "Corrigé envoyé. Pense à vérifier les spams si besoin.";
    els.correctionEmailStatus.className = "completion-email-status success";
    els.correctionEmail.value = "";
  } catch (error) {
    els.correctionEmailStatus.textContent = error.message || "Le mail n’a pas pu être envoyé.";
    els.correctionEmailStatus.className = "completion-email-status error";
  } finally {
    if (button) button.disabled = false;
  }
}

function renderCompletionReview() {
  if (!els.completionReview) return;

  const mistakeCards = ALL_CARDS.filter((card) => state.mistakeCardIds.has(card.id));
  state.earnedBadges = state.settings.enableBadges ? computeBadges() : [];
  const weakCompetencies = Object.entries(state.competencyStats)
    .filter(([, stats]) => stats.errors > 0)
    .sort((a, b) => b[1].errors - a[1].errors)
    .map(([key]) => COMPETENCY_LABELS[key] || key);
  const strongCompetencies = Object.entries(state.competencyStats)
    .filter(([, stats]) => stats.success > 0 && stats.errors === 0)
    .map(([key]) => COMPETENCY_LABELS[key] || key);
  const message = mistakeCards.length
    ? `À retravailler : ${mistakeCards.map((card) => card.title).slice(0, 3).join(", ")}${mistakeCards.length > 3 ? "..." : ""}`
    : `Bravo : les ${STEPS.length} étapes sont maîtrisées sans carte à retravailler.`;

  els.completionReview.innerHTML = `
    <div class="completion-review-item">
      <strong>${state.maxStreak >= 3 ? `Meilleure série : ${state.maxStreak}` : "Bilan pédagogique"}</strong>
      <p>${escapeHtml(message)}</p>
    </div>
    <div class="completion-review-item">
      <strong>Badges gagnés</strong>
      <p>${state.earnedBadges.length ? state.earnedBadges.map((badge) => `${badge.title} — ${badge.text}`).join(" · ") : "Aucun badge pour cette partie : vise une série, moins d’erreurs ou une compétence sans faute."}</p>
    </div>
    <div class="completion-review-item">
      <strong>Compétences</strong>
      <p>Maîtrisées : ${escapeHtml(strongCompetencies.join(", ") || "à consolider")}<br>À revoir : ${escapeHtml(weakCompetencies.join(", ") || "aucune priorité détectée")}</p>
    </div>
    <div class="completion-review-item">
      <strong>Erreurs expliquées</strong>
      <p>${mistakeCards.length ? mistakeCards.slice(0, 5).map((card) => {
        const step = STEPS.find((item) => item.id === card.stepId);
        return `${card.title} → ${cardErrorExplanation(card, step)}`;
      }).join(" · ") : "Aucune erreur à expliquer."}</p>
    </div>
  `;

  if (els.replayErrorsButton) {
    els.replayErrorsButton.hidden = mistakeCards.length === 0;
  }
}

function render() {
  renderTopbar();
  renderOrderGame();
  renderActiveCard();
  renderMiniProgress();
  renderSteps();
  renderHint();
  renderCompletion();
  saveState();
}

function buildSessionProgress(phase, lastAction = "") {
  const orderPlaced = state.order ? state.order.slots.filter((stepId) => stepId !== null).length : 0;
  return {
    phase,
    orderSolved: Boolean(state.order?.solved),
    orderPlaced,
    cardsPlaced: state.idx,
    totalCards: state.cards.length || ALL_CARDS.length,
    masteredSteps: state.masteredSteps.size,
    score: state.score,
    errors: state.errors,
    streak: state.streak,
    seconds: state.seconds,
    lastAction,
  };
}

function queueSessionProgress(phase = null, lastAction = "") {
  if (!SESSION) return;

  const inferredPhase = phase
    || (state.done ? "completed" : state.order && !state.order.solved ? "order" : "cards");
  latestProgressPayload = buildSessionProgress(inferredPhase, lastAction);
  window.clearTimeout(progressTimerId);
  progressTimerId = window.setTimeout(sendSessionProgress, 350);
}

function sendSessionProgress() {
  if (!SESSION || !latestProgressPayload || progressInFlight) return;

  const progress = latestProgressPayload;
  latestProgressPayload = null;
  progressInFlight = true;
  window.fetch("session_progress.php", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
    },
    body: JSON.stringify({
      code: SESSION.code,
      participantId: SESSION.participantId,
      progress,
    }),
  }).catch(() => {
    latestProgressPayload = progress;
  }).finally(() => {
    progressInFlight = false;
    if (latestProgressPayload) {
      progressTimerId = window.setTimeout(sendSessionProgress, 700);
    }
  });
}

function place(stepId) {
  if (state.locked) return;
  if (state.order && !state.order.solved) {
    state.order.feedback = `Valide d'abord l'ordre des ${STEPS.length} étapes avant de classer les cartes.`;
    state.order.tone = "error";
    renderOrderGame();
    setLearningText("Repère d'abord le chemin complet : idée, marché, chiffres, financement, juridique, création.");
    return;
  }

  const card = currentCard();
  if (!card) return;

  const slotIndex = Array.from({ length: stepCardCount(stepId) }, (_, index) => index)
    .findIndex((index) => !state.placements[`${stepId}_${index}`]);
  if (slotIndex === -1) return;

  if (card.stepId === stepId) {
    state.placements[`${stepId}_${slotIndex}`] = card;
    state.idx += 1;
    state.errorStepId = null;
    state.streak += 1;
    state.maxStreak = Math.max(state.maxStreak, state.streak);
    trackCompetency(card, true);
    updateScore(cardPoints(card));
    let feedback = `À retenir : ${cardSuccessExplanation(card)}`;
    if (state.streak > 0 && state.streak % 3 === 0) {
      updateScore(SCORE.streakBonus);
      feedback = `Série x${state.streak} : tu commences à reconnaître la logique du parcours. ${cardSuccessExplanation(card)}`;
    }
    let toast = `+${cardPoints(card)} pts`;
    if (state.streak > 0 && state.streak % 3 === 0) toast += ` · série x${state.streak} (+${SCORE.streakBonus})`;
    if (isComplete(stepId)) {
      state.masteredSteps.add(stepId);
      const masteredStep = STEPS.find((step) => step.id === stepId);
      feedback = `Étape maîtrisée : ${masteredStep ? masteredStep.title : "étape validée"}. ${cardSuccessExplanation(card)}`;
      toast = `Étape « ${masteredStep ? masteredStep.short || masteredStep.title : "validée"} » complète ! ${toast}`;
    }
    setLearningText(feedback);
    haptic("success");
    if (state.idx >= state.cards.length) {
      state.done = true;
      window.clearInterval(timerId);
      clearSavedState();
      hideToast();
      queueSessionProgress("completed", "Partie terminée");
      submitSessionResult();
    } else {
      // Sur mobile la note pédagogique est masquée : le toast porte l'explication.
      showToast(isCompactScreen() ? `✓ ${toast} — ${cardSuccessExplanation(card)}` : `✓ ${toast}`, "success", isCompactScreen() ? 3200 : 1800);
      queueSessionProgress("cards", `Carte validée : ${card.title}`);
    }
    render();
    return;
  }

  state.errors += 1;
  state.streak = 0;
  state.mistakeCardIds.add(card.id);
  trackCompetency(card, false);
  updateScore(SCORE.error);
  state.errorStepId = stepId;
  state.locked = true;
  const expectedStep = STEPS.find((step) => step.id === card.stepId);
  const errorText = state.settings.showHints
    ? `Ce n'est pas la bonne étape. ${cardErrorExplanation(card, expectedStep)}`
    : "Ce n'est pas encore la bonne étape. Relis le verbe d'action et compare avec le rôle de chaque étape.";
  els.hintText.textContent = errorText;
  setLearningText(state.settings.showHints
    ? "Indice pédagogique : cherche si la carte parle d’idée, de marché, d’argent, de financement, de droit ou de formalités."
    : "Conseil : repère le verbe d’action, puis compare-le au rôle général de chaque étape.");
  haptic("error");
  showToast(`✕ ${errorText}`, "error");
  queueSessionProgress(state.order && !state.order.solved ? "order" : "cards", `Erreur sur : ${card.title}`);
  renderTopbar();
  renderSteps();
  saveState();
  // Verrou court : évite d'enchaîner plusieurs erreurs sur un double tap.
  window.setTimeout(() => {
    state.locked = false;
    if (state.errorStepId === stepId) {
      state.errorStepId = null;
      renderSteps();
      renderHint();
    }
  }, 600);
}

// Résultats de séance non envoyés (réseau coupé au moment de la fin) : gardés en
// local et renvoyés au retour du réseau ou au prochain lancement.
const PENDING_RESULTS_KEY = "six-etapes:pending-results";

function readPendingResults() {
  try {
    const list = JSON.parse(window.localStorage.getItem(PENDING_RESULTS_KEY) || "[]");
    return Array.isArray(list) ? list : [];
  } catch {
    return [];
  }
}

function writePendingResults(list) {
  try {
    if (list.length) {
      window.localStorage.setItem(PENDING_RESULTS_KEY, JSON.stringify(list.slice(-10)));
    } else {
      window.localStorage.removeItem(PENDING_RESULTS_KEY);
    }
  } catch {
    // Stockage indisponible : le résultat sera perdu si le réseau ne revient pas avant la fermeture.
  }
}

function postSessionResult(payload) {
  return window.fetch("session_result.php", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
    },
    body: JSON.stringify(payload),
  }).then((response) => {
    // Une réponse serveur (même 4xx : séance close, doublon) vaut « traité » : on ne rejoue pas.
    if (!response.ok && response.status >= 500) throw new Error(`HTTP ${response.status}`);
    return response;
  });
}

let flushingPending = false;
function flushPendingResults() {
  if (flushingPending) return;
  const pending = readPendingResults();
  if (!pending.length) return;
  flushingPending = true;
  const [first, ...rest] = pending;
  postSessionResult(first)
    .then(() => {
      writePendingResults(rest);
      if (rest.length) window.setTimeout(flushPendingResults, 300);
      else showToast("Résultat envoyé au classement.", "success", 2200);
    })
    .catch(() => {
      // Toujours hors-ligne : on garde la file telle quelle.
    })
    .finally(() => {
      flushingPending = false;
    });
}

function submitSessionResult() {
  if (!SESSION || state.resultSubmitted) return;

  state.resultSubmitted = true;
  sendSessionProgress();
  const payload = {
    code: SESSION.code,
    participantId: SESSION.participantId,
    seconds: state.seconds,
    errors: state.errors,
    cards: state.cards.length,
    score: state.score,
    mistakes: [...state.mistakeCardIds],
    badges: state.earnedBadges,
    competencies: state.competencyStats,
    finishedAt: Date.now(),
  };
  postSessionResult(payload).catch(() => {
    writePendingResults([...readPendingResults(), payload]);
    showToast("Hors connexion : ton résultat sera envoyé dès le retour du réseau.", "", 3600);
  });
}

function replayErrors() {
  const mistakeCards = ALL_CARDS.filter((card) => state.mistakeCardIds.has(card.id));
  if (!mistakeCards.length) return;

  clearSavedState();
  state.cards = shuffle(mistakeCards);
  state.idx = 0;
  state.placements = {};
  state.errors = 0;
  state.score = 0;
  state.streak = 0;
  state.maxStreak = 0;
  state.masteredSteps = new Set();
  state.mistakeCardIds = new Set();
  state.errorStepId = null;
  state.done = false;
  state.completionDismissed = false;
  state.resultSubmitted = true;
  if (els.completionScreen) {
    els.completionScreen.hidden = true;
    els.completionScreen.classList.add("is-closed");
    els.completionScreen.style.display = "none";
  }
  restartTimer();
  render();
}

function skipCard() {
  if (!currentCard() || state.cards.length - state.idx < 2) return;
  const skipped = state.cards.splice(state.idx, 1)[0];
  state.cards.push(skipped);
  haptic("light");
  showToast("Carte mise de côté : elle reviendra en fin de partie.", "", 1800);
  queueSessionProgress(state.order && !state.order.solved ? "order" : "cards", `Carte passée : ${skipped.title}`);
  render();
}

function showHint() {
  if (!state.settings.showHints) return;
  const card = currentCard();
  if (!card) return;
  if (state.order && !state.order.solved) {
    showToast(`Indice : commence par remettre les ${STEPS.length} étapes dans l'ordre.`, "", 2400);
    return;
  }
  updateScore(SCORE.hint);
  const expectedStep = STEPS.find((step) => step.id === card.stepId);
  const hint = state.mode === "discovery"
    ? `Indice : cette carte va dans "${expectedStep ? expectedStep.title : ""}".`
    : `Conseil : compare la carte avec l’objectif de l’étape "${expectedStep ? expectedStep.short || expectedStep.title : ""}".`;
  els.hintText.textContent = hint;
  haptic("light");
  showToast(`💡 ${hint} (${SCORE.hint} pts)`, "", 3200);
  renderTopbar();
  saveState();
}

function closeCompletion() {
  state.completionDismissed = true;
  state.done = false;
  els.completionScreen.hidden = true;
  els.completionScreen.classList.add("is-closed");
  els.completionScreen.style.display = "none";
}

function applySettings() {
  applyTheme();
  syncTimer();
  render();
}

function bindEvents() {
  if (!state.settings.showHints && els.hintButton) {
    els.hintButton.hidden = true;
  }

  if (els.hintButton) {
    els.hintButton.addEventListener("click", showHint);
  }
  els.skipCardButton.addEventListener("click", skipCard);
  els.resetButton.addEventListener("click", confirmResetGame);
  if (els.playAgainButton) {
    els.playAgainButton.addEventListener("click", resetGame);
  }
  if (els.playSoloButton) {
    els.playSoloButton.addEventListener("click", playSoloAgain);
  }
  if (els.replayErrorsButton) {
    els.replayErrorsButton.addEventListener("click", replayErrors);
  }
  if (els.orderValidateButton) {
    els.orderValidateButton.addEventListener("click", validateOrderGame);
  }
  if (els.orderResetButton) {
    els.orderResetButton.addEventListener("click", resetOrderGame);
  }
  if (els.correctionEmailForm) {
    els.correctionEmailForm.addEventListener("submit", sendCorrectionEmail);
  }
  if (els.startIntroButton) {
    els.startIntroButton.addEventListener("click", startIntro);
  }
  els.closeCompletionButton.addEventListener("click", closeCompletion);
  els.completionScreen.addEventListener("click", (event) => {
    if (event.target === els.completionScreen) {
      closeCompletion();
    }
  });
  els.modeButtons.forEach((button) => {
    button.addEventListener("click", () => setMode(button.dataset.mode));
  });
  setMode(SESSION ? "classroom" : "discovery");
  let resizeTimerId = null;
  window.addEventListener("resize", () => {
    window.clearTimeout(resizeTimerId);
    resizeTimerId = window.setTimeout(render, 120);
  });
  // Sauvegarde immédiate quand l'onglet passe en arrière-plan (retour à l'accueil sur iPhone).
  document.addEventListener("visibilitychange", () => {
    if (document.visibilityState === "hidden") saveState();
  });
}

window.closeCompletionOverlay = closeCompletion;
// Lu par assets/pwa.js : une mise à jour n'est proposée qu'en dehors d'une partie en cours.
window.sixEtapesGameInProgress = () => !document.body.classList.contains("intro-active") && !state.done && state.idx > 0;

function init() {
  if (IN_APP) document.body.classList.add("in-app");
  bindEvents();
  applySettings();
  if (!restoreState()) {
    resetGame();
  }
  if (SESSION || readPendingResults().length) {
    flushPendingResults();
    window.addEventListener("online", () => window.setTimeout(flushPendingResults, 500));
  }
  queueSessionProgress("waiting", "Connecté");
  if (SESSION) {
    window.setInterval(() => {
      queueSessionProgress(null, state.done ? "Partie terminée" : "En cours");
    }, 5000);
  }
}

init();
