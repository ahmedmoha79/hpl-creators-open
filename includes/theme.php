<?php
/**
 * Dark mode (2026-11-09). Follows the phone/computer setting by default ("Auto");
 * a person can pick Light or Dark on Profile (saved in this browser only).
 * The tiny script runs before the page paints, so there is no white flash.
 */
function theme_head(string $lightColor = '#f3f0eb'): void
{
    $u = fn($f) => (function_exists('u') ? u($f) : $f) . '?v=' . THEME_CSS_VER;
    echo '<meta name="color-scheme" content="light dark">';
    echo '<meta name="theme-color" content="' . e($lightColor) . '" media="(prefers-color-scheme: light)">';
    echo '<meta name="theme-color" content="#14100d" media="(prefers-color-scheme: dark)">';
    echo "<script>(function(){var d=document.documentElement,m=window.matchMedia&&matchMedia('(prefers-color-scheme: dark)');"
        . "function p(){try{return localStorage.getItem('hplTheme')||'auto'}catch(e){return'auto'}}"
        . "function a(){var v=p(),k=v==='dark'||(v!=='light'&&m&&m.matches);d.setAttribute('data-theme',k?'dark':'light');d.setAttribute('data-theme-pref',v);"
        . "document.querySelectorAll('meta[name=theme-color]').forEach(function(t){t.setAttribute('content',k?'#14100d':'" . e($lightColor) . "')})}"
        . "a();if(m&&m.addEventListener)m.addEventListener('change',a);"
        . "window.hplTheme={get:p,set:function(v){try{if(v==='auto')localStorage.removeItem('hplTheme');else localStorage.setItem('hplTheme',v)}catch(e){}a()}}})();</script>";
    echo '<link rel="stylesheet" href="' . e($u('assets/css/dark-auto.css')) . '">';
    echo '<link rel="stylesheet" href="' . e($u('assets/css/dark.css')) . '">';
}
const THEME_CSS_VER = '20261112a';
