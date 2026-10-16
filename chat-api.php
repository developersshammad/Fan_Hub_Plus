<?php
/**
 * Alternate address for the ORA chat API (same code as api/chat.php).
 * Some shared hosts (e.g. InfinityFree) block requests to /api/ paths with HTTP 403;
 * the chat widget falls back to this file automatically when that happens.
 */
require __DIR__ . '/api/chat.php';
