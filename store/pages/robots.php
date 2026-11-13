<?php
header('Content-Type: text/plain; charset=utf-8');
echo "User-agent: *\nAllow: /\nDisallow: /admin/\nDisallow: /api/\nDisallow: /order\nDisallow: /track\nDisallow: /fr/order\nDisallow: /fr/track\nDisallow: /sw/order\nDisallow: /sw/track\n\nSitemap: https://" . site_host() . "/sitemap.xml\n";
