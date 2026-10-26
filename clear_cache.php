<?php
// Vider le cache OPcache — supprimer ce fichier après usage
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo 'OPcache vidé avec succès.';
} else {
    echo 'OPcache non disponible.';
}
