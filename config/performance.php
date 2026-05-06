<?php
/**
 * Runtime performance tuning
 */

// OPcache — ensure it's enabled and tuned (php.ini overrides preferred)
if (function_exists('opcache_get_status')) {
    // These directives caused issues on the live server, so they remain disabled here.
    /*
    @ini_set('opcache.enable',              1);
    @ini_set('opcache.memory_consumption',  128);
    @ini_set('opcache.max_accelerated_files', 4000);
    @ini_set('opcache.revalidate_freq',     60);
    @ini_set('opcache.fast_shutdown',       1);
    */
}

// Leave APCu at the environment default settings.
