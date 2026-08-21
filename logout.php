<?php
/**
 * Smart Eats - sign out
 * Ends the session but keeps the basket so an unfinished order is not lost.
 */
require_once __DIR__ . '/includes/auth.php';

logout_user();
flash('You have been signed out.', 'info');
redirect('index.php');
