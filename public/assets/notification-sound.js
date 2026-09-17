(() => {
  'use strict';

  const presetNames = new Set(['soft', 'chime', 'pulse', 'minimal']);
  const scriptPath = document.currentScript?.src ? new URL(document.currentScript.src, window.location.href).pathname : '';
  const inferredBasePath = scriptPath.endsWith('/assets/notification-sound.js')
    ? scriptPath.slice(0, -'/assets/notification-sound.js'.length)
    : '';
  const defaultEndpoint = `${inferredBasePath}/account/notification-sound`;
  const state = {
    context: null,
    unlocked: false,
    muted: false,
    volume: 0.65,
    defaultSoundKey: 'soft',
    categories: new Map(),
  };

  const clamp = (value, min, max) => Math.min(max, Math.max(min, value));
  const validCategory = (value) => typeof value === 'string' && /^[a-z][a-z0-9_.-]{1,95}$/.test(value);
  const validPreset = (value) => typeof value === 'string' && presetNames.has(value);

  const removeUnlockListeners = () => {
    window.removeEventListener('pointerdown', unlock, true);
    window.removeEventListener('keydown', unlock, true);
    window.removeEventListener('touchstart', unlock, true);
  };

  async function unlock() {
    if (state.unlocked) return true;
    const AudioContext = window.AudioContext || window.webkitAudioContext;
    if (!AudioContext) return false;

    try {
      state.context ||= new AudioContext();
      if (state.context.state === 'suspended') await state.context.resume();
      state.unlocked = state.context.state === 'running';
      if (state.unlocked) {
        removeUnlockListeners();
        window.dispatchEvent(new CustomEvent('forwext:notification-sound-ready'));
      }
      return state.unlocked;
    } catch (_) {
      return false;
    }
  }

  const envelope = (gain, start, duration, peak) => {
    gain.gain.cancelScheduledValues(start);
    gain.gain.setValueAtTime(0.0001, start);
    gain.gain.exponentialRampToValueAtTime(Math.max(0.0001, peak), start + 0.012);
    gain.gain.exponentialRampToValueAtTime(0.0001, start + duration);
  };

  const tone = (frequency, startOffset, duration, type, volume) => {
    if (!state.context) return;
    const now = state.context.currentTime + startOffset;
    const oscillator = state.context.createOscillator();
    const gain = state.context.createGain();
    oscillator.type = type;
    oscillator.frequency.setValueAtTime(frequency, now);
    envelope(gain, now, duration, Math.max(0.0001, volume));
    oscillator.connect(gain);
    gain.connect(state.context.destination);
    oscillator.start(now);
    oscillator.stop(now + duration + 0.02);
  };

  const playPreset = (preset, volume) => {
    switch (preset) {
      case 'chime':
        tone(740, 0, 0.12, 'sine', volume * 0.48);
        tone(988, 0.075, 0.16, 'sine', volume * 0.38);
        break;
      case 'pulse':
        tone(520, 0, 0.08, 'triangle', volume * 0.4);
        tone(660, 0.095, 0.09, 'triangle', volume * 0.34);
        break;
      case 'minimal':
        tone(620, 0, 0.075, 'sine', volume * 0.32);
        break;
      case 'soft':
      default:
        tone(660, 0, 0.14, 'sine', volume * 0.34);
        tone(520, 0.055, 0.13, 'sine', volume * 0.22);
        break;
    }
  };

  const configure = (config) => {
    if (!config || typeof config !== 'object') return false;
    state.muted = config.muted === true;
    state.volume = clamp(Number(config.volume) / 100, 0, 1);
    state.defaultSoundKey = validPreset(config.default_sound_key) ? config.default_sound_key : 'soft';
    state.categories.clear();

    if (Array.isArray(config.categories)) {
      for (const item of config.categories) {
        if (!item || !validCategory(item.category_key)) continue;
        state.categories.set(item.category_key, {
          enabled: item.enabled !== false,
          soundKey: validPreset(item.sound_key) ? item.sound_key : null,
        });
      }
    }
    return true;
  };

  const play = (categoryKey) => {
    if (!state.unlocked || !state.context || state.muted || state.volume <= 0) return false;
    if (!validCategory(categoryKey)) return false;
    const category = state.categories.get(categoryKey);
    if (category && category.enabled === false) return false;
    const preset = category?.soundKey || state.defaultSoundKey;
    playPreset(validPreset(preset) ? preset : 'soft', state.volume);
    return true;
  };

  const preview = (preset) => {
    if (!state.unlocked || !state.context || state.muted || state.volume <= 0 || !validPreset(preset)) return false;
    playPreset(preset, state.volume);
    return true;
  };

  const load = async (endpoint = defaultEndpoint) => {
    try {
      const response = await fetch(endpoint, {
        method: 'GET',
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
        cache: 'no-store',
      });
      if (!response.ok) return false;
      return configure(await response.json());
    } catch (_) {
      return false;
    }
  };

  window.addEventListener('pointerdown', unlock, true);
  window.addEventListener('keydown', unlock, true);
  window.addEventListener('touchstart', unlock, true);

  window.ForwextNotificationSound = Object.freeze({
    configure,
    load,
    play,
    preview,
    unlock,
    status: () => Object.freeze({
      supported: Boolean(window.AudioContext || window.webkitAudioContext),
      unlocked: state.unlocked,
      muted: state.muted,
      volume: state.volume,
      defaultSoundKey: state.defaultSoundKey,
    }),
  });
})();
