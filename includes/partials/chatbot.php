<?php
require_once __DIR__ . '/../chatbot_engine.php';
/* ORA chatbot widget — answers from api/chat.php (knowledge base + live site data, optional AI). Expects $chatSuggestions */
$botName = setting('chatbot_name', 'ORA');
$defaultWelcome = "Hey, I'm ORA — your Fandom Universe guide. Ask me about characters, trending content, events, merch or recommendations.";
$welcome = setting('chatbot_welcome', $defaultWelcome);
$aiOn = function_exists('ora_ai_enabled') && ora_ai_enabled();
if ($aiOn && ($welcome === '' || $welcome === $defaultWelcome)) {
    $welcome = "Hey, I'm **$botName** — your AI assistant on Fan Hub Plus. Ask me anything: characters, events and merch here, or homework, coding, writing, translations and general questions. You can also send a picture.\nI understand English, اردو and Roman Urdu.";
} elseif (!$aiOn) {
    $welcome .= "\n" . 'Tip: I understand English, Urdu & Roman Urdu — try "Kaien vs Nyx", "events this weekend" or "quiz me".';
}
$chips = array_column($chatSuggestions ?? [], 'question');
if (!$chips) $chips = ["What's trending?", 'Show me characters', 'Upcoming events', 'Give me a tour'];
$chips = array_values(array_unique(array_merge($chips, ['Quiz me', 'Surprise me'])));
?>
<div id="chat-teaser" class="pointer-events-none fixed bottom-[104px] right-5 z-[75] max-w-[240px] translate-y-2 rounded-2xl rounded-br-sm glass px-4 py-3 text-sm text-white/85 opacity-0 shadow-2xl transition-all duration-500 sm:bottom-[118px] sm:right-8" role="status">
  <button type="button" data-chat-open class="text-left" data-cursor-hover>
    <span class="block text-[10px] font-semibold uppercase tracking-[0.25em] text-violet"><?= e($botName) ?> is here</span>
    Need a hand finding something? Ask me anything ✦
  </button>
</div>

<button id="chat-toggle" type="button" data-cursor-hover aria-label="Open <?= e($botName) ?> assistant" aria-expanded="false" aria-controls="chat-panel"
  class="magnetic-btn fixed bottom-6 right-5 z-[76] flex h-16 w-16 items-center justify-center rounded-full text-white shadow-[0_0_40px_rgba(139,92,246,0.55)] transition-transform hover:scale-105 sm:bottom-8 sm:right-8"
  style="background:radial-gradient(circle at 30% 30%, #a78bfa, #6d28d9 60%, #2e1065)">
  <span class="chat-ping absolute inset-0 animate-ping rounded-full bg-violet/40" style="animation-duration:2.5s"></span>
  <span class="chat-icon-open relative"><?= icon('sparkles', 22) ?></span><span class="chat-icon-close relative hidden"><?= icon('x', 22) ?></span>
</button>

<section id="chat-panel" role="dialog" aria-label="<?= e($botName) ?> assistant" aria-hidden="true"
  class="chat-panel fixed bottom-24 left-3 right-3 z-[76] flex flex-col overflow-hidden rounded-3xl glass shadow-[0_30px_120px_-20px_rgba(139,92,246,0.45)] transition-all duration-500 ease-[cubic-bezier(.22,1,.36,1)] sm:bottom-28 sm:left-auto sm:right-8 sm:w-[410px] pointer-events-none opacity-0 translate-y-6 scale-95">

  <!-- Header -->
  <header class="relative flex items-center gap-3 border-b border-white/10 px-5 py-4">
    <div class="pointer-events-none absolute inset-0 opacity-60" style="background:radial-gradient(120% 140% at 0% 0%, rgba(139,92,246,0.25), transparent 60%)"></div>
    <span class="relative flex h-10 w-10 flex-none items-center justify-center rounded-full bg-gradient-to-br from-violet to-magenta text-white shadow-[0_0_24px_rgba(139,92,246,0.6)]"><?= icon('bot', 18) ?></span>
    <div class="relative min-w-0 flex-1">
      <p class="font-display text-xl leading-none tracking-wide text-white"><?= e($botName) ?> <span class="text-violet">Assistant</span></p>
      <p class="mt-1 flex items-center gap-1.5 text-[11px] text-emerald-400"><span class="h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-400"></span> <span id="chat-status">Online</span> <span id="chat-mode" class="text-white/40">· <?= $aiOn ? 'AI powered' : 'Smart fandom guide' ?></span></p>
    </div>
    <button type="button" id="chat-expand" class="relative hidden h-9 w-9 items-center justify-center rounded-full text-white/60 transition hover:bg-white/10 hover:text-white sm:flex" title="Expand" aria-label="Expand assistant" aria-pressed="false" data-cursor-hover><span class="chat-exp-on"><?= icon('maximize-2', 15) ?></span><span class="chat-exp-off hidden"><?= icon('minimize-2', 15) ?></span></button>
    <button type="button" id="chat-clear" class="relative flex h-9 w-9 items-center justify-center rounded-full text-white/60 transition hover:bg-white/10 hover:text-white" title="New conversation" aria-label="Start a new conversation" data-cursor-hover><?= icon('rotate-ccw', 16) ?></button>
    <button type="button" id="chat-close" class="relative flex h-9 w-9 items-center justify-center rounded-full text-white/60 transition hover:bg-white/10 hover:text-white" aria-label="Close assistant" data-cursor-hover><?= icon('x', 17) ?></button>
  </header>

  <!-- Messages -->
  <div id="chat-messages" class="flex-1 space-y-4 overflow-y-auto overscroll-contain px-4 py-5 no-scrollbar" data-lenis-prevent aria-live="polite" aria-relevant="additions">
    <div class="chat-row flex items-start gap-2" data-welcome>
      <span class="flex h-7 w-7 flex-none items-center justify-center rounded-full bg-gradient-to-br from-violet to-magenta text-white"><?= icon('sparkle', 13) ?></span>
      <div class="chat-bubble chat-bot" data-chat-md><?= nl2br(e($welcome)) ?></div>
    </div>
  </div>

  <!-- Suggestions -->
  <div id="chat-suggestions" class="flex gap-2 overflow-x-auto px-4 pb-3 no-scrollbar" data-lenis-prevent>
    <?php foreach ($chips as $s): ?>
      <button type="button" data-chat-suggest="<?= e($s) ?>" class="flex-none rounded-full border border-white/15 bg-white/[0.03] px-3 py-1.5 text-[11px] text-white/75 transition hover:border-violet/60 hover:text-white" data-cursor-hover><?= e($s) ?></button>
    <?php endforeach; ?>
  </div>

  <!-- Composer -->
  <form id="chat-form" class="border-t border-white/10 p-3" autocomplete="off">
    <div id="chat-attach-preview" class="hidden pb-2"></div>
    <div class="flex items-end gap-1.5 rounded-2xl border border-white/10 bg-white/5 p-1.5 pl-2 transition focus-within:border-violet/60">
      <button type="button" id="chat-attach" class="hidden h-9 w-9 flex-none items-center justify-center rounded-full text-white/60 transition hover:bg-white/10 hover:text-white" aria-label="Attach an image" title="Attach an image (or paste one)" data-cursor-hover><?= icon('image', 16) ?></button>
      <input type="file" id="chat-file" accept="image/jpeg,image/png,image/webp,image/gif" class="hidden" tabindex="-1" aria-hidden="true">
      <textarea id="chat-input" rows="1" maxlength="4000" placeholder="Ask <?= e($botName) ?> anything…" aria-label="Message"
        class="max-h-40 min-h-[38px] flex-1 resize-none bg-transparent py-2 pl-2 text-sm leading-snug text-white outline-none placeholder:text-white/40"></textarea>
      <button type="button" id="chat-mic" class="hidden h-9 w-9 flex-none items-center justify-center rounded-full text-white/60 transition hover:bg-white/10 hover:text-white" aria-label="Speak your question" title="Voice input" data-cursor-hover><?= icon('mic', 16) ?></button>
      <button type="submit" id="chat-send" class="flex h-9 w-9 flex-none items-center justify-center rounded-full bg-gradient-to-br from-violet to-magenta text-white transition hover:shadow-[0_0_20px_rgba(139,92,246,0.7)] disabled:opacity-40" aria-label="Send" title="Send" data-cursor-hover><span class="chat-send-icon"><?= icon('send', 15) ?></span><span class="chat-stop-icon hidden"><?= icon('square-stop', 14) ?></span></button>
    </div>
    <p class="mt-2 text-center text-[10px] text-white/30">Ask anything · English · اردو · Roman Urdu · AI can make mistakes</p>
  </form>
</section>

<template id="tpl-chat-avatar"><span class="flex h-7 w-7 flex-none items-center justify-center rounded-full bg-gradient-to-br from-violet to-magenta text-white"><?= icon('sparkle', 13) ?></span></template>
<template id="tpl-icon-thumbs-up"><?= icon('thumbs-up', 12) ?></template>
<template id="tpl-icon-thumbs-down"><?= icon('thumbs-down', 12) ?></template>
<template id="tpl-icon-arrow-up-right"><?= icon('arrow-up-right', 12) ?></template>
<template id="tpl-icon-calendar"><?= icon('calendar', 16) ?></template>
<template id="tpl-icon-calendar-plus"><?= icon('calendar-plus', 12) ?></template>
<template id="tpl-icon-copy"><?= icon('copy', 12) ?></template>
<template id="tpl-icon-check"><?= icon('check', 12) ?></template>
<template id="tpl-icon-volume-2"><?= icon('volume-2', 12) ?></template>
<template id="tpl-icon-square-stop"><?= icon('square-stop', 12) ?></template>
<template id="tpl-icon-rotate-ccw"><?= icon('rotate-ccw', 12) ?></template>
<template id="tpl-icon-x"><?= icon('x', 12) ?></template>
