<?php
require_once __DIR__ . '/config.php';
logout();
flash('info', 'You have been signed out.');
redirect('login.php?out=1'); // 2026-11-11: to sign-in, not the advert page (the home-screen app showed the website after logging out)
