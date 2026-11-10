<?php
// === SECURITY HEADERS ===
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    // SECURITY: Restrict browser features/APIs not needed by the gallery application
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    // SECURITY: Isolate browsing context from cross-origin openers/popups (prevent XS-Leaks / Spectre-style window interactions)
    header('Cross-Origin-Opener-Policy: same-origin');
}

// === CONFIGURAZIONE ===
$baseDir      = __DIR__;                                            // cartella principale
$configName   = ".Magic-Photo-Gallery";                             // nome della cartella di configurazione
$configDir    = $baseDir . DIRECTORY_SEPARATOR . $configName;       // path per tutti i file generati da questo script
$configFile   = $configDir . DIRECTORY_SEPARATOR . '.config.json';  // file di configurazione
$thumbsRoot   = $configDir . DIRECTORY_SEPARATOR . '.thumbs';       // path per le miniature
$prefThumb    = '.tbn_';                                            // prefisso per file thumbnail
$prefFolder   = '.fld_';                                            // prefisso per le sottocartelle cache
$imgExt = ['jpg','jpeg','png','heic','heif'];           // estensioni supportate (solo minuscolo)

// Nome del file script corrente (senza path). Evita hardcoding.
$self = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');

// Configurazione di default
$defaults = [
    'title'       => 'Galleria foto',
    'thumb_make'  => 320,        // lato lungo delle anteprime generate
    'thumb_view'  => 320,        // lato della miniatura mostrata in griglia
    'debugFlag'   => false,
    // Colori day/night
    'bg_light'      => '#f6f6f6',
    'fg_light'      => '#111111',
    'bg_dark'       => '#111111',
    'fg_dark'       => '#eeeeee',
    // Cartelle da escludere
    'exclude_dirs'  => [ $configName, 'log', 'logs' ],  // ulteriori cartelle da escludere
    // GIF di cartella
    'folder_gif_mode'          => 'transition', // 'transition' | 'cut'
    'folder_gif_fade_frames'   => 6,            // frame intermedi per dissolvenza tra due foto (usato in 'transition')
    'folder_gif_max_frames'    => 12,           // massimo numero di foto di partenza
    'folder_gif_delay_cs'      => 80,            // ritardo di base per frame (centisecondi)
    // Modalità autenticazione: 0=nessun login, 1=login solo per funzioni extra, 2=login per tutto
    'auth_mode'     => 0,
    'auth_password' => '',  // può essere testo in chiaro o password_hash() ($2y$...)
    // Lingua UI: 'auto' (da browser) oppure codice lingua (es. 'it', 'en', 'de')
    'language'      => 'auto'
];
// Carica o crea .config.json
$cfg = $defaults;
$exclude = [];
if (is_file($configFile)) {
    $raw = @file_get_contents($configFile);
    $json = json_decode($raw, true);
    if (is_array($json)) {
        $cfg = array_merge($cfg, array_intersect_key($json, $defaults));
        if (isset($json['exclude_dirs']) && is_array($json['exclude_dirs'])) {
            $exclude = $json['exclude_dirs'];
        }
    }
    // Integra il file esistente con eventuali nuove chiavi di default
    $needsSave = false;
    $jsonUpdated = is_array($json) ? $json : [];
    foreach ($defaults as $k => $v) {
        if (!array_key_exists($k, $jsonUpdated)) {
            $jsonUpdated[$k] = $v;
            $needsSave = true;
        }
    }
    if ($needsSave) {
        @file_put_contents($configFile, json_encode($jsonUpdated, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    }
} else {
    @file_put_contents($configFile, json_encode($defaults, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
}
$cfg['title'] = (string)$cfg['title'];
$cfg['thumb_make'] = max(32, (int)$cfg['thumb_make']);
$cfg['thumb_view'] = max(48, (int)$cfg['thumb_view']);
// GIF cfg
$cfg['folder_gif_mode'] = in_array(($cfg['folder_gif_mode'] ?? 'transition'), ['transition','cut'], true) ? $cfg['folder_gif_mode'] : 'transition';
$cfg['folder_gif_fade_frames'] = max(0, (int)($cfg['folder_gif_fade_frames'] ?? 6));
$cfg['folder_gif_max_frames'] = max(1, (int)($cfg['folder_gif_max_frames'] ?? 12));
$cfg['folder_gif_delay_cs'] = max(1, (int)($cfg['folder_gif_delay_cs'] ?? 8));
// Backward-compatibilità: se esiste 'auth_enabled' nel file di config, mappa a auth_mode (true->2, false->0) solo se 'auth_mode' non è definito
if (!array_key_exists('auth_mode', $cfg) && array_key_exists('auth_enabled', $cfg)) {
    $cfg['auth_mode'] = !empty($cfg['auth_enabled']) ? 2 : 0;
}
// Sanitizza
foreach (['bg_light','fg_light','bg_dark','fg_dark'] as $k) {
    if (!preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', (string)$cfg[$k])) {
        $cfg[$k] = $defaults[$k];
    }
}
// Esclusioni directory: unisci defaults + config utente, normalizza e de-duplica
$ex = array_merge($defaults['exclude_dirs'], is_array($exclude) ? $exclude : []);
$ex = array_values(array_unique(array_map(function($s){
    $s = trim((string)$s);
    $s = str_replace(['\\'], '/', $s);
    $s = trim($s, '/');
    return $s;
}, $ex)));
$cfg['exclude_dirs'] = $ex;
// Debug flag
$cfg['debugFlag'] = !empty($cfg['debugFlag']);
$debugFlag = $cfg['debugFlag'];
// Auth
$cfg['auth_mode'] = isset($cfg['auth_mode']) ? (int)$cfg['auth_mode'] : 0;
if ($cfg['auth_mode'] < 0 || $cfg['auth_mode'] > 2) { $cfg['auth_mode'] = 0; }
$cfg['auth_password'] = isset($cfg['auth_password']) ? (string)$cfg['auth_password'] : '';

// === I18N ===
// Ordine chiavi: le traduzioni sono array posizionali (nome nativo + stringhe). Placeholder: {dirs} {thumbs} {gifs} {v} {c} {r} {x} {g} {y} {hash}
function mpg_i18n_keys(): array {
    return ['login_required','password','enter','wrong_password','home','auth_extra','auth_no','auth_in','menu','purge','purge_t','sync','sync_t','mkhash','logout','login','folders','photos','no_images','viewer','close','prev','next','info','cancel','c_purge_all','c_purge_dir','c_sync_all','c_sync_dir','e_purge','e_sync','e_hash','e_generic','purge_done','sync_done','sync_sum','sync_title','sync_sub','done','enter_pwd','denied','auth_err','hash_prompt','hash_done','exif_na','language','auto','dims','camera','lens','date','focal','aperture','exptime','present'];
}
function mpg_i18n_data(): array {
    return [
    'en' => ['English','Access required','Password','Enter','Wrong password','Home','Signed in (extra features enabled)','Not signed in (required for extra features)','Signed in','Menu','Clear cache','Delete thumbnails and GIFs of the current folder','Sync cache','Sync cache with the current folder contents','Create hash','Sign out','Sign in','Folders','Photos','No images found.','Photo viewer','Close viewer (Esc)','Previous photo (Left arrow)','Next photo (Right arrow)','Information and EXIF data (i)','Cancel','Delete ALL thumbnails and GIFs of ALL folders?','Delete thumbnails and GIF of the current folder?','Sync the cache on ALL folders? Removes obsolete files and regenerates missing/old thumbnails.','Sync the cache on the current folder? Removes obsolete files and regenerates missing/old thumbnails.','Error clearing cache','Error syncing cache','Error generating hash','Error','Cache cleared.\nFolders: {dirs}\nThumbs: {thumbs}\nGIFs: {gifs}','Sync completed.','Visited {v} folders • thumbnails: {c} created, {r} updated, {x} removed • GIFs: {g} updated, {y} removed','Syncing cache…','Analyzing folders and thumbnails, please wait.','Completed','Enter the password','Access denied','Authentication error','Enter the new password to turn into a hash (it will not be saved automatically):','Hash generated and copied to clipboard:\n\n{hash}\n\nPaste it into ".config.json" under "auth_password".','not available','Language','Auto (browser)','Dimensions','Camera','Lens','Date','Focal length','Aperture','Exposure','present'],
    'it' => ['Italiano','Accesso richiesto','Password','Entra','Password errata','Home','Accesso eseguito (funzioni extra abilitate)','Non autenticato (richiesto per funzioni extra)','Accesso eseguito','Menu','Pulisci cache','Elimina anteprime e GIF della cartella corrente','Sincronizza cache','Sincronizza cache con il contenuto attuale delle cartelle','Crea hash','Esci','Accedi','Cartelle','Foto','Nessuna immagine trovata.','Visualizzatore foto','Chiudi visualizzatore (Esc)','Foto precedente (Freccia sinistra)','Foto successiva (Freccia destra)','Informazioni e dati EXIF (i)','Annulla','Eliminare TUTTE le anteprime e GIF di TUTTE le cartelle?','Eliminare le anteprime e la GIF della cartella corrente?','Sincronizzare la cache su TUTTE le cartelle? Rimuove file obsoleti e rigenera miniature mancanti/vecchie.','Sincronizzare la cache sulla cartella corrente? Rimuove file obsoleti e rigenera miniature mancanti/vecchie.','Errore nella pulizia cache','Errore nella sincronizzazione cache','Errore nella generazione hash','Errore','Cache pulita.\nCartelle toccate: {dirs}\nThumbs: {thumbs}\nGIF: {gifs}','Sincronizzazione completata.','Visitate {v} cartelle • anteprime: {c} create, {r} aggiornate, {x} rimosse • GIF: {g} aggiornate, {y} rimosse','Sincronizzazione cache…','Analisi cartelle e anteprime, attendere.','Completato','Inserisci la password','Accesso negato','Errore di autenticazione','Inserisci la nuova password da trasformare in hash (non verrà salvata automaticamente):','Hash generato e copiato negli appunti:\n\n{hash}\n\nIncollalo in ".config.json" su "auth_password".','non disponibili','Lingua','Automatica (browser)','Dimensioni','Fotocamera','Obiettivo','Data','Lunghezza focale','Apertura','Esposizione','presente'],
    'fr' => ['Français','Accès requis','Mot de passe','Entrer','Mot de passe incorrect','Accueil','Connecté (fonctions supplémentaires activées)','Non connecté (requis pour les fonctions supplémentaires)','Connecté','Menu','Vider le cache','Supprimer les miniatures et GIF du dossier actuel','Synchroniser le cache','Synchroniser le cache avec le contenu actuel des dossiers','Créer un hash','Se déconnecter','Se connecter','Dossiers','Photos','Aucune image trouvée.','Visionneuse de photos','Fermer la visionneuse (Échap)','Photo précédente (flèche gauche)','Photo suivante (flèche droite)','Informations et données EXIF (i)','Annuler','Supprimer TOUTES les miniatures et GIF de TOUS les dossiers ?','Supprimer les miniatures et le GIF du dossier actuel ?','Synchroniser le cache sur TOUS les dossiers ? Supprime les fichiers obsolètes et régénère les miniatures manquantes/anciennes.','Synchroniser le cache sur le dossier actuel ? Supprime les fichiers obsolètes et régénère les miniatures manquantes/anciennes.','Erreur lors du vidage du cache','Erreur lors de la synchronisation du cache','Erreur lors de la génération du hash','Erreur','Cache vidé.\nDossiers : {dirs}\nMiniatures : {thumbs}\nGIF : {gifs}','Synchronisation terminée.','{v} dossiers visités • miniatures : {c} créées, {r} mises à jour, {x} supprimées • GIF : {g} mis à jour, {y} supprimés','Synchronisation du cache…','Analyse des dossiers et des miniatures, veuillez patienter.','Terminé','Saisissez le mot de passe','Accès refusé','Erreur d’authentification','Saisissez le nouveau mot de passe à transformer en hash (il ne sera pas enregistré automatiquement) :','Hash généré et copié dans le presse-papiers :\n\n{hash}\n\nCollez-le dans « .config.json » sous « auth_password ».','non disponibles','Langue','Automatique (navigateur)','Dimensions','Appareil','Objectif','Date','Focale','Ouverture','Exposition','présent'],
    'de' => ['Deutsch','Zugang erforderlich','Passwort','Eintreten','Falsches Passwort','Startseite','Angemeldet (Zusatzfunktionen aktiviert)','Nicht angemeldet (für Zusatzfunktionen erforderlich)','Angemeldet','Menü','Cache leeren','Vorschaubilder und GIFs des aktuellen Ordners löschen','Cache synchronisieren','Cache mit dem aktuellen Ordnerinhalt synchronisieren','Hash erstellen','Abmelden','Anmelden','Ordner','Fotos','Keine Bilder gefunden.','Fotobetrachter','Betrachter schließen (Esc)','Vorheriges Foto (Pfeil links)','Nächstes Foto (Pfeil rechts)','Informationen und EXIF-Daten (i)','Abbrechen','ALLE Vorschaubilder und GIFs ALLER Ordner löschen?','Vorschaubilder und GIF des aktuellen Ordners löschen?','Cache für ALLE Ordner synchronisieren? Entfernt veraltete Dateien und erzeugt fehlende/alte Vorschaubilder neu.','Cache für den aktuellen Ordner synchronisieren? Entfernt veraltete Dateien und erzeugt fehlende/alte Vorschaubilder neu.','Fehler beim Leeren des Caches','Fehler bei der Cache-Synchronisierung','Fehler beim Erstellen des Hashs','Fehler','Cache geleert.\nOrdner: {dirs}\nVorschaubilder: {thumbs}\nGIFs: {gifs}','Synchronisierung abgeschlossen.','{v} Ordner besucht • Vorschaubilder: {c} erstellt, {r} aktualisiert, {x} entfernt • GIFs: {g} aktualisiert, {y} entfernt','Cache wird synchronisiert…','Ordner und Vorschaubilder werden analysiert, bitte warten.','Abgeschlossen','Passwort eingeben','Zugriff verweigert','Authentifizierungsfehler','Neues Passwort eingeben, das in einen Hash umgewandelt werden soll (wird nicht automatisch gespeichert):','Hash erzeugt und in die Zwischenablage kopiert:\n\n{hash}\n\nFüge ihn in „.config.json“ unter „auth_password“ ein.','nicht verfügbar','Sprache','Automatisch (Browser)','Abmessungen','Kamera','Objektiv','Datum','Brennweite','Blende','Belichtungszeit','vorhanden'],
    'es' => ['Español','Acceso requerido','Contraseña','Entrar','Contraseña incorrecta','Inicio','Sesión iniciada (funciones extra habilitadas)','Sin sesión (necesaria para funciones extra)','Sesión iniciada','Menú','Vaciar caché','Eliminar miniaturas y GIF de la carpeta actual','Sincronizar caché','Sincronizar la caché con el contenido actual de las carpetas','Crear hash','Cerrar sesión','Iniciar sesión','Carpetas','Fotos','No se encontraron imágenes.','Visor de fotos','Cerrar visor (Esc)','Foto anterior (flecha izquierda)','Foto siguiente (flecha derecha)','Información y datos EXIF (i)','Cancelar','¿Eliminar TODAS las miniaturas y GIF de TODAS las carpetas?','¿Eliminar las miniaturas y el GIF de la carpeta actual?','¿Sincronizar la caché en TODAS las carpetas? Elimina archivos obsoletos y regenera miniaturas faltantes/antiguas.','¿Sincronizar la caché en la carpeta actual? Elimina archivos obsoletos y regenera miniaturas faltantes/antiguas.','Error al vaciar la caché','Error al sincronizar la caché','Error al generar el hash','Error','Caché vaciada.\nCarpetas: {dirs}\nMiniaturas: {thumbs}\nGIF: {gifs}','Sincronización completada.','{v} carpetas visitadas • miniaturas: {c} creadas, {r} actualizadas, {x} eliminadas • GIF: {g} actualizados, {y} eliminados','Sincronizando caché…','Analizando carpetas y miniaturas, espera.','Completado','Introduce la contraseña','Acceso denegado','Error de autenticación','Introduce la nueva contraseña para convertirla en hash (no se guardará automáticamente):','Hash generado y copiado al portapapeles:\n\n{hash}\n\nPégalo en «.config.json» en «auth_password».','no disponibles','Idioma','Automático (navegador)','Dimensiones','Cámara','Objetivo','Fecha','Distancia focal','Apertura','Exposición','presente'],
    'pt' => ['Português','Acesso necessário','Palavra-passe','Entrar','Palavra-passe incorreta','Início','Sessão iniciada (funções extra ativadas)','Sem sessão (necessária para funções extra)','Sessão iniciada','Menu','Limpar cache','Eliminar miniaturas e GIF da pasta atual','Sincronizar cache','Sincronizar a cache com o conteúdo atual das pastas','Criar hash','Terminar sessão','Iniciar sessão','Pastas','Fotos','Nenhuma imagem encontrada.','Visualizador de fotos','Fechar visualizador (Esc)','Foto anterior (seta esquerda)','Foto seguinte (seta direita)','Informações e dados EXIF (i)','Cancelar','Eliminar TODAS as miniaturas e GIF de TODAS as pastas?','Eliminar as miniaturas e o GIF da pasta atual?','Sincronizar a cache em TODAS as pastas? Remove ficheiros obsoletos e regenera miniaturas em falta/antigas.','Sincronizar a cache na pasta atual? Remove ficheiros obsoletos e regenera miniaturas em falta/antigas.','Erro ao limpar a cache','Erro ao sincronizar a cache','Erro ao gerar o hash','Erro','Cache limpa.\nPastas: {dirs}\nMiniaturas: {thumbs}\nGIF: {gifs}','Sincronização concluída.','{v} pastas visitadas • miniaturas: {c} criadas, {r} atualizadas, {x} removidas • GIF: {g} atualizados, {y} removidos','A sincronizar cache…','A analisar pastas e miniaturas, aguarde.','Concluído','Introduza a palavra-passe','Acesso negado','Erro de autenticação','Introduza a nova palavra-passe a converter em hash (não será guardada automaticamente):','Hash gerado e copiado para a área de transferência:\n\n{hash}\n\nCole-o em «.config.json» em «auth_password».','não disponíveis','Idioma','Automático (navegador)','Dimensões','Câmara','Objetiva','Data','Distância focal','Abertura','Exposição','presente'],
    'nl' => ['Nederlands','Toegang vereist','Wachtwoord','Inloggen','Onjuist wachtwoord','Home','Ingelogd (extra functies ingeschakeld)','Niet ingelogd (vereist voor extra functies)','Ingelogd','Menu','Cache wissen','Miniaturen en GIF’s van de huidige map verwijderen','Cache synchroniseren','Cache synchroniseren met de huidige inhoud van de mappen','Hash maken','Uitloggen','Inloggen','Mappen','Foto’s','Geen afbeeldingen gevonden.','Fotoviewer','Viewer sluiten (Esc)','Vorige foto (pijl links)','Volgende foto (pijl rechts)','Informatie en EXIF-gegevens (i)','Annuleren','ALLE miniaturen en GIF’s van ALLE mappen verwijderen?','Miniaturen en GIF van de huidige map verwijderen?','Cache synchroniseren voor ALLE mappen? Verwijdert verouderde bestanden en maakt ontbrekende/oude miniaturen opnieuw.','Cache synchroniseren voor de huidige map? Verwijdert verouderde bestanden en maakt ontbrekende/oude miniaturen opnieuw.','Fout bij het wissen van de cache','Fout bij het synchroniseren van de cache','Fout bij het maken van de hash','Fout','Cache gewist.\nMappen: {dirs}\nMiniaturen: {thumbs}\nGIF’s: {gifs}','Synchronisatie voltooid.','{v} mappen bezocht • miniaturen: {c} gemaakt, {r} bijgewerkt, {x} verwijderd • GIF’s: {g} bijgewerkt, {y} verwijderd','Cache synchroniseren…','Mappen en miniaturen worden geanalyseerd, even geduld.','Voltooid','Voer het wachtwoord in','Toegang geweigerd','Authenticatiefout','Voer het nieuwe wachtwoord in dat in een hash moet worden omgezet (wordt niet automatisch opgeslagen):','Hash gegenereerd en gekopieerd naar het klembord:\n\n{hash}\n\nPlak het in ".config.json" bij "auth_password".','niet beschikbaar','Taal','Automatisch (browser)','Afmetingen','Camera','Objectief','Datum','Brandpuntsafstand','Diafragma','Belichtingstijd','aanwezig'],
    'pl' => ['Polski','Wymagany dostęp','Hasło','Wejdź','Nieprawidłowe hasło','Strona główna','Zalogowano (funkcje dodatkowe włączone)','Niezalogowano (wymagane dla funkcji dodatkowych)','Zalogowano','Menu','Wyczyść pamięć podręczną','Usuń miniatury i GIF-y bieżącego folderu','Synchronizuj pamięć podręczną','Synchronizuj pamięć podręczną z bieżącą zawartością folderów','Utwórz hash','Wyloguj','Zaloguj','Foldery','Zdjęcia','Nie znaleziono obrazów.','Przeglądarka zdjęć','Zamknij przeglądarkę (Esc)','Poprzednie zdjęcie (strzałka w lewo)','Następne zdjęcie (strzałka w prawo)','Informacje i dane EXIF (i)','Anuluj','Usunąć WSZYSTKIE miniatury i GIF-y ze WSZYSTKICH folderów?','Usunąć miniatury i GIF bieżącego folderu?','Zsynchronizować pamięć podręczną dla WSZYSTKICH folderów? Usuwa przestarzałe pliki i odtwarza brakujące/stare miniatury.','Zsynchronizować pamięć podręczną dla bieżącego folderu? Usuwa przestarzałe pliki i odtwarza brakujące/stare miniatury.','Błąd czyszczenia pamięci podręcznej','Błąd synchronizacji pamięci podręcznej','Błąd generowania hasha','Błąd','Wyczyszczono pamięć podręczną.\nFoldery: {dirs}\nMiniatury: {thumbs}\nGIF-y: {gifs}','Synchronizacja zakończona.','Odwiedzone foldery: {v} • miniatury: {c} utworzone, {r} zaktualizowane, {x} usunięte • GIF-y: {g} zaktualizowane, {y} usunięte','Synchronizacja pamięci podręcznej…','Analiza folderów i miniatur, proszę czekać.','Ukończono','Wpisz hasło','Odmowa dostępu','Błąd uwierzytelniania','Wpisz nowe hasło do zamiany na hash (nie zostanie zapisane automatycznie):','Hash wygenerowany i skopiowany do schowka:\n\n{hash}\n\nWklej go do „.config.json” w polu „auth_password”.','niedostępne','Język','Automatyczny (przeglądarka)','Wymiary','Aparat','Obiektyw','Data','Ogniskowa','Przysłona','Czas naświetlania','obecne'],
    'ro' => ['Română','Acces necesar','Parolă','Intră','Parolă greșită','Acasă','Autentificat (funcții suplimentare activate)','Neautentificat (necesar pentru funcții suplimentare)','Autentificat','Meniu','Golește memoria cache','Șterge miniaturile și GIF-urile din dosarul curent','Sincronizează memoria cache','Sincronizează memoria cache cu conținutul actual al dosarelor','Creează hash','Deconectare','Autentificare','Dosare','Fotografii','Nu s-au găsit imagini.','Vizualizator foto','Închide vizualizatorul (Esc)','Fotografia anterioară (săgeata stânga)','Fotografia următoare (săgeata dreapta)','Informații și date EXIF (i)','Anulează','Ștergi TOATE miniaturile și GIF-urile din TOATE dosarele?','Ștergi miniaturile și GIF-ul din dosarul curent?','Sincronizezi memoria cache pentru TOATE dosarele? Elimină fișierele învechite și regenerează miniaturile lipsă/vechi.','Sincronizezi memoria cache pentru dosarul curent? Elimină fișierele învechite și regenerează miniaturile lipsă/vechi.','Eroare la golirea memoriei cache','Eroare la sincronizarea memoriei cache','Eroare la generarea hash-ului','Eroare','Memorie cache golită.\nDosare: {dirs}\nMiniaturi: {thumbs}\nGIF-uri: {gifs}','Sincronizare finalizată.','{v} dosare vizitate • miniaturi: {c} create, {r} actualizate, {x} eliminate • GIF-uri: {g} actualizate, {y} eliminate','Se sincronizează memoria cache…','Se analizează dosarele și miniaturile, așteaptă.','Finalizat','Introdu parola','Acces refuzat','Eroare de autentificare','Introdu parola nouă de transformat în hash (nu va fi salvată automat):','Hash generat și copiat în clipboard:\n\n{hash}\n\nLipește-l în „.config.json” la „auth_password”.','indisponibile','Limbă','Automat (browser)','Dimensiuni','Aparat foto','Obiectiv','Dată','Distanță focală','Apertură','Expunere','prezent'],
    'cs' => ['Čeština','Vyžadován přístup','Heslo','Vstoupit','Nesprávné heslo','Domů','Přihlášen (extra funkce povoleny)','Nepřihlášen (vyžadováno pro extra funkce)','Přihlášen','Nabídka','Vymazat mezipaměť','Smazat náhledy a GIFy aktuální složky','Synchronizovat mezipaměť','Synchronizovat mezipaměť s aktuálním obsahem složek','Vytvořit hash','Odhlásit se','Přihlásit se','Složky','Fotky','Nenalezeny žádné obrázky.','Prohlížeč fotek','Zavřít prohlížeč (Esc)','Předchozí fotka (šipka vlevo)','Další fotka (šipka vpravo)','Informace a data EXIF (i)','Zrušit','Smazat VŠECHNY náhledy a GIFy ve VŠECH složkách?','Smazat náhledy a GIF aktuální složky?','Synchronizovat mezipaměť pro VŠECHNY složky? Odstraní zastaralé soubory a znovu vytvoří chybějící/staré náhledy.','Synchronizovat mezipaměť pro aktuální složku? Odstraní zastaralé soubory a znovu vytvoří chybějící/staré náhledy.','Chyba při mazání mezipaměti','Chyba při synchronizaci mezipaměti','Chyba při generování hashe','Chyba','Mezipaměť vymazána.\nSložky: {dirs}\nNáhledy: {thumbs}\nGIFy: {gifs}','Synchronizace dokončena.','Navštívené složky: {v} • náhledy: {c} vytvořeno, {r} aktualizováno, {x} odstraněno • GIFy: {g} aktualizováno, {y} odstraněno','Synchronizace mezipaměti…','Analyzuji složky a náhledy, čekejte prosím.','Dokončeno','Zadejte heslo','Přístup odepřen','Chyba ověření','Zadejte nové heslo, které se převede na hash (nebude automaticky uloženo):','Hash vytvořen a zkopírován do schránky:\n\n{hash}\n\nVložte jej do „.config.json“ do „auth_password“.','nedostupné','Jazyk','Automaticky (prohlížeč)','Rozměry','Fotoaparát','Objektiv','Datum','Ohnisková vzdálenost','Clona','Expoziční čas','přítomno'],
    'sk' => ['Slovenčina','Vyžaduje sa prístup','Heslo','Vstúpiť','Nesprávne heslo','Domov','Prihlásený (extra funkcie povolené)','Neprihlásený (vyžaduje sa pre extra funkcie)','Prihlásený','Ponuka','Vymazať vyrovnávaciu pamäť','Odstrániť náhľady a GIFy aktuálneho priečinka','Synchronizovať vyrovnávaciu pamäť','Synchronizovať vyrovnávaciu pamäť s aktuálnym obsahom priečinkov','Vytvoriť hash','Odhlásiť sa','Prihlásiť sa','Priečinky','Fotky','Nenašli sa žiadne obrázky.','Prehliadač fotiek','Zavrieť prehliadač (Esc)','Predchádzajúca fotka (šípka vľavo)','Nasledujúca fotka (šípka vpravo)','Informácie a údaje EXIF (i)','Zrušiť','Odstrániť VŠETKY náhľady a GIFy zo VŠETKÝCH priečinkov?','Odstrániť náhľady a GIF aktuálneho priečinka?','Synchronizovať vyrovnávaciu pamäť pre VŠETKY priečinky? Odstráni zastarané súbory a znova vytvorí chýbajúce/staré náhľady.','Synchronizovať vyrovnávaciu pamäť pre aktuálny priečinok? Odstráni zastarané súbory a znova vytvorí chýbajúce/staré náhľady.','Chyba pri mazaní vyrovnávacej pamäte','Chyba pri synchronizácii vyrovnávacej pamäte','Chyba pri generovaní hashu','Chyba','Vyrovnávacia pamäť vymazaná.\nPriečinky: {dirs}\nNáhľady: {thumbs}\nGIFy: {gifs}','Synchronizácia dokončená.','Navštívené priečinky: {v} • náhľady: {c} vytvorených, {r} aktualizovaných, {x} odstránených • GIFy: {g} aktualizovaných, {y} odstránených','Synchronizácia vyrovnávacej pamäte…','Analyzujem priečinky a náhľady, čakajte.','Dokončené','Zadajte heslo','Prístup zamietnutý','Chyba overenia','Zadajte nové heslo na prevod na hash (neuloží sa automaticky):','Hash vytvorený a skopírovaný do schránky:\n\n{hash}\n\nVložte ho do „.config.json“ do „auth_password“.','nedostupné','Jazyk','Automaticky (prehliadač)','Rozmery','Fotoaparát','Objektív','Dátum','Ohnisková vzdialenosť','Clona','Expozičný čas','prítomné'],
    'sl' => ['Slovenščina','Potreben dostop','Geslo','Vstopi','Napačno geslo','Domov','Prijavljen (dodatne funkcije omogočene)','Ni prijave (potrebno za dodatne funkcije)','Prijavljen','Meni','Počisti predpomnilnik','Izbriši predoglede in GIF-e trenutne mape','Sinhroniziraj predpomnilnik','Sinhroniziraj predpomnilnik s trenutno vsebino map','Ustvari hash','Odjava','Prijava','Mape','Fotografije','Ni najdenih slik.','Pregledovalnik fotografij','Zapri pregledovalnik (Esc)','Prejšnja fotografija (puščica levo)','Naslednja fotografija (puščica desno)','Informacije in podatki EXIF (i)','Prekliči','Izbrišem VSE predoglede in GIF-e v VSEH mapah?','Izbrišem predoglede in GIF trenutne mape?','Sinhroniziram predpomnilnik za VSE mape? Odstrani zastarele datoteke in znova ustvari manjkajoče/stare predoglede.','Sinhroniziram predpomnilnik za trenutno mapo? Odstrani zastarele datoteke in znova ustvari manjkajoče/stare predoglede.','Napaka pri čiščenju predpomnilnika','Napaka pri sinhronizaciji predpomnilnika','Napaka pri ustvarjanju hasha','Napaka','Predpomnilnik počiščen.\nMape: {dirs}\nPredogledi: {thumbs}\nGIF-i: {gifs}','Sinhronizacija končana.','Obiskanih map: {v} • predogledi: {c} ustvarjenih, {r} posodobljenih, {x} odstranjenih • GIF-i: {g} posodobljenih, {y} odstranjenih','Sinhronizacija predpomnilnika…','Analiziram mape in predoglede, počakajte.','Končano','Vnesite geslo','Dostop zavrnjen','Napaka pri overjanju','Vnesite novo geslo za pretvorbo v hash (ne bo samodejno shranjeno):','Hash ustvarjen in kopiran v odložišče:\n\n{hash}\n\nVstavite ga v „.config.json“ pri „auth_password“.','ni na voljo','Jezik','Samodejno (brskalnik)','Mere','Fotoaparat','Objektiv','Datum','Goriščna razdalja','Zaslonka','Ekspozicija','prisotno'],
    'hr' => ['Hrvatski','Potreban pristup','Lozinka','Uđi','Pogrešna lozinka','Početna','Prijavljen (dodatne funkcije omogućene)','Nije prijavljen (potrebno za dodatne funkcije)','Prijavljen','Izbornik','Očisti predmemoriju','Izbriši sličice i GIF-ove trenutne mape','Sinkroniziraj predmemoriju','Sinkroniziraj predmemoriju s trenutnim sadržajem mapa','Izradi hash','Odjava','Prijava','Mape','Fotografije','Nema pronađenih slika.','Preglednik fotografija','Zatvori preglednik (Esc)','Prethodna fotografija (strelica lijevo)','Sljedeća fotografija (strelica desno)','Informacije i EXIF podaci (i)','Odustani','Izbrisati SVE sličice i GIF-ove iz SVIH mapa?','Izbrisati sličice i GIF trenutne mape?','Sinkronizirati predmemoriju za SVE mape? Uklanja zastarjele datoteke i ponovno izrađuje nedostajuće/stare sličice.','Sinkronizirati predmemoriju za trenutnu mapu? Uklanja zastarjele datoteke i ponovno izrađuje nedostajuće/stare sličice.','Pogreška pri čišćenju predmemorije','Pogreška pri sinkronizaciji predmemorije','Pogreška pri izradi hasha','Pogreška','Predmemorija očišćena.\nMape: {dirs}\nSličice: {thumbs}\nGIF-ovi: {gifs}','Sinkronizacija dovršena.','Posjećeno mapa: {v} • sličice: {c} izrađeno, {r} ažurirano, {x} uklonjeno • GIF-ovi: {g} ažurirano, {y} uklonjeno','Sinkronizacija predmemorije…','Analiza mapa i sličica, pričekajte.','Dovršeno','Unesite lozinku','Pristup odbijen','Pogreška autentifikacije','Unesite novu lozinku za pretvaranje u hash (neće se automatski spremiti):','Hash izrađen i kopiran u međuspremnik:\n\n{hash}\n\nZalijepite ga u „.config.json“ pod „auth_password“.','nije dostupno','Jezik','Automatski (preglednik)','Dimenzije','Fotoaparat','Objektiv','Datum','Žarišna duljina','Otvor blende','Ekspozicija','prisutno'],
    'bg' => ['Български','Необходим е достъп','Парола','Вход','Грешна парола','Начало','Вписан (допълнителните функции са включени)','Не сте вписани (необходимо за допълнителни функции)','Вписан','Меню','Изчисти кеша','Изтрий миниатюрите и GIF файловете на текущата папка','Синхронизирай кеша','Синхронизирай кеша със съдържанието на папките','Създай хеш','Изход','Вход','Папки','Снимки','Няма намерени изображения.','Преглед на снимки','Затвори прегледа (Esc)','Предишна снимка (стрелка наляво)','Следваща снимка (стрелка надясно)','Информация и EXIF данни (i)','Отказ','Да се изтрият ВСИЧКИ миниатюри и GIF файлове от ВСИЧКИ папки?','Да се изтрият миниатюрите и GIF файла на текущата папка?','Да се синхронизира кешът за ВСИЧКИ папки? Премахва остарели файлове и създава наново липсващите/старите миниатюри.','Да се синхронизира кешът за текущата папка? Премахва остарели файлове и създава наново липсващите/старите миниатюри.','Грешка при изчистване на кеша','Грешка при синхронизиране на кеша','Грешка при създаване на хеш','Грешка','Кешът е изчистен.\nПапки: {dirs}\nМиниатюри: {thumbs}\nGIF: {gifs}','Синхронизирането завърши.','Посетени папки: {v} • миниатюри: {c} създадени, {r} обновени, {x} премахнати • GIF: {g} обновени, {y} премахнати','Синхронизиране на кеша…','Анализ на папки и миниатюри, моля изчакайте.','Готово','Въведете паролата','Достъпът е отказан','Грешка при удостоверяване','Въведете новата парола, която да се превърне в хеш (няма да се запази автоматично):','Хешът е създаден и копиран в клипборда:\n\n{hash}\n\nПоставете го в „.config.json“ в „auth_password“.','не е наличен','Език','Автоматично (браузър)','Размери','Фотоапарат','Обектив','Дата','Фокусно разстояние','Бленда','Експонация','налично'],
    'el' => ['Ελληνικά','Απαιτείται πρόσβαση','Κωδικός πρόσβασης','Είσοδος','Λάθος κωδικός πρόσβασης','Αρχική','Συνδεδεμένος (ενεργές οι πρόσθετες λειτουργίες)','Μη συνδεδεμένος (απαιτείται για πρόσθετες λειτουργίες)','Συνδεδεμένος','Μενού','Εκκαθάριση προσωρινής μνήμης','Διαγραφή μικρογραφιών και GIF του τρέχοντος φακέλου','Συγχρονισμός προσωρινής μνήμης','Συγχρονισμός της προσωρινής μνήμης με το τρέχον περιεχόμενο των φακέλων','Δημιουργία hash','Αποσύνδεση','Σύνδεση','Φάκελοι','Φωτογραφίες','Δεν βρέθηκαν εικόνες.','Προβολέας φωτογραφιών','Κλείσιμο προβολέα (Esc)','Προηγούμενη φωτογραφία (αριστερό βέλος)','Επόμενη φωτογραφία (δεξί βέλος)','Πληροφορίες και δεδομένα EXIF (i)','Άκυρο','Διαγραφή ΟΛΩΝ των μικρογραφιών και GIF από ΟΛΟΥΣ τους φακέλους;','Διαγραφή των μικρογραφιών και του GIF του τρέχοντος φακέλου;','Συγχρονισμός της προσωρινής μνήμης για ΟΛΟΥΣ τους φακέλους; Αφαιρεί παρωχημένα αρχεία και ξαναδημιουργεί τις ελλείπουσες/παλιές μικρογραφίες.','Συγχρονισμός της προσωρινής μνήμης για τον τρέχοντα φάκελο; Αφαιρεί παρωχημένα αρχεία και ξαναδημιουργεί τις ελλείπουσες/παλιές μικρογραφίες.','Σφάλμα κατά την εκκαθάριση της προσωρινής μνήμης','Σφάλμα κατά τον συγχρονισμό της προσωρινής μνήμης','Σφάλμα κατά τη δημιουργία hash','Σφάλμα','Η προσωρινή μνήμη εκκαθαρίστηκε.\nΦάκελοι: {dirs}\nΜικρογραφίες: {thumbs}\nGIF: {gifs}','Ο συγχρονισμός ολοκληρώθηκε.','Επισκέψεις σε {v} φακέλους • μικρογραφίες: {c} δημιουργήθηκαν, {r} ενημερώθηκαν, {x} αφαιρέθηκαν • GIF: {g} ενημερώθηκαν, {y} αφαιρέθηκαν','Συγχρονισμός προσωρινής μνήμης…','Ανάλυση φακέλων και μικρογραφιών, παρακαλώ περιμένετε.','Ολοκληρώθηκε','Εισαγάγετε τον κωδικό πρόσβασης','Άρνηση πρόσβασης','Σφάλμα ταυτοποίησης','Εισαγάγετε τον νέο κωδικό για μετατροπή σε hash (δεν θα αποθηκευτεί αυτόματα):','Το hash δημιουργήθηκε και αντιγράφηκε στο πρόχειρο:\n\n{hash}\n\nΕπικολλήστε το στο «.config.json» στο «auth_password».','μη διαθέσιμα','Γλώσσα','Αυτόματα (πρόγραμμα περιήγησης)','Διαστάσεις','Κάμερα','Φακός','Ημερομηνία','Εστιακό μήκος','Διάφραγμα','Έκθεση','υπάρχει'],
    'hu' => ['Magyar','Hozzáférés szükséges','Jelszó','Belépés','Hibás jelszó','Kezdőlap','Bejelentkezve (extra funkciók engedélyezve)','Nincs bejelentkezve (extra funkciókhoz szükséges)','Bejelentkezve','Menü','Gyorsítótár törlése','Az aktuális mappa bélyegképeinek és GIF-jeinek törlése','Gyorsítótár szinkronizálása','Gyorsítótár szinkronizálása a mappák aktuális tartalmával','Hash létrehozása','Kijelentkezés','Bejelentkezés','Mappák','Fotók','Nem található kép.','Fotómegjelenítő','Megjelenítő bezárása (Esc)','Előző fotó (bal nyíl)','Következő fotó (jobb nyíl)','Információk és EXIF-adatok (i)','Mégse','Törli AZ ÖSSZES bélyegképet és GIF-et AZ ÖSSZES mappából?','Törli az aktuális mappa bélyegképeit és GIF-jét?','Szinkronizálja a gyorsítótárat AZ ÖSSZES mappára? Eltávolítja az elavult fájlokat, és újragenerálja a hiányzó/régi bélyegképeket.','Szinkronizálja a gyorsítótárat az aktuális mappára? Eltávolítja az elavult fájlokat, és újragenerálja a hiányzó/régi bélyegképeket.','Hiba a gyorsítótár törlésekor','Hiba a gyorsítótár szinkronizálásakor','Hiba a hash létrehozásakor','Hiba','Gyorsítótár törölve.\nMappák: {dirs}\nBélyegképek: {thumbs}\nGIF-ek: {gifs}','A szinkronizálás befejeződött.','{v} mappa érintve • bélyegképek: {c} létrehozva, {r} frissítve, {x} eltávolítva • GIF-ek: {g} frissítve, {y} eltávolítva','Gyorsítótár szinkronizálása…','Mappák és bélyegképek elemzése, kérjük, várjon.','Kész','Adja meg a jelszót','Hozzáférés megtagadva','Hitelesítési hiba','Adja meg az új jelszót, amelyből hash készül (nem kerül automatikusan mentésre):','A hash létrejött és a vágólapra másolódott:\n\n{hash}\n\nMásolja be a „.config.json” fájl „auth_password” mezőjébe.','nem érhető el','Nyelv','Automatikus (böngésző)','Méretek','Fényképezőgép','Objektív','Dátum','Gyújtótávolság','Rekesz','Expozíció','jelen van'],
    'fi' => ['Suomi','Pääsy vaaditaan','Salasana','Kirjaudu','Väärä salasana','Etusivu','Kirjautunut (lisätoiminnot käytössä)','Ei kirjautunut (vaaditaan lisätoimintoihin)','Kirjautunut','Valikko','Tyhjennä välimuisti','Poista nykyisen kansion pikkukuvat ja GIFit','Synkronoi välimuisti','Synkronoi välimuisti kansioiden nykyiseen sisältöön','Luo tiiviste','Kirjaudu ulos','Kirjaudu sisään','Kansiot','Kuvat','Kuvia ei löytynyt.','Kuvankatselin','Sulje katselin (Esc)','Edellinen kuva (vasen nuoli)','Seuraava kuva (oikea nuoli)','Tiedot ja EXIF-tiedot (i)','Peruuta','Poistetaanko KAIKKI pikkukuvat ja GIFit KAIKISTA kansioista?','Poistetaanko nykyisen kansion pikkukuvat ja GIF?','Synkronoidaanko välimuisti KAIKILLE kansioille? Poistaa vanhentuneet tiedostot ja luo puuttuvat/vanhat pikkukuvat uudelleen.','Synkronoidaanko välimuisti nykyiselle kansiolle? Poistaa vanhentuneet tiedostot ja luo puuttuvat/vanhat pikkukuvat uudelleen.','Virhe välimuistin tyhjennyksessä','Virhe välimuistin synkronoinnissa','Virhe tiivisteen luonnissa','Virhe','Välimuisti tyhjennetty.\nKansiot: {dirs}\nPikkukuvat: {thumbs}\nGIFit: {gifs}','Synkronointi valmis.','Käytyjä kansioita: {v} • pikkukuvat: {c} luotu, {r} päivitetty, {x} poistettu • GIFit: {g} päivitetty, {y} poistettu','Synkronoidaan välimuistia…','Analysoidaan kansioita ja pikkukuvia, odota.','Valmis','Anna salasana','Pääsy estetty','Tunnistautumisvirhe','Anna uusi salasana, josta tehdään tiiviste (sitä ei tallenneta automaattisesti):','Tiiviste luotu ja kopioitu leikepöydälle:\n\n{hash}\n\nLiitä se tiedostoon ".config.json" kohtaan "auth_password".','ei saatavilla','Kieli','Automaattinen (selain)','Mitat','Kamera','Objektiivi','Päivämäärä','Polttoväli','Aukko','Valotusaika','läsnä'],
    'sv' => ['Svenska','Åtkomst krävs','Lösenord','Logga in','Fel lösenord','Hem','Inloggad (extrafunktioner aktiverade)','Inte inloggad (krävs för extrafunktioner)','Inloggad','Meny','Rensa cache','Ta bort miniatyrer och GIF:ar i aktuell mapp','Synkronisera cache','Synkronisera cachen med mapparnas aktuella innehåll','Skapa hash','Logga ut','Logga in','Mappar','Foton','Inga bilder hittades.','Fotovisare','Stäng visaren (Esc)','Föregående foto (vänsterpil)','Nästa foto (högerpil)','Information och EXIF-data (i)','Avbryt','Ta bort ALLA miniatyrer och GIF:ar i ALLA mappar?','Ta bort miniatyrer och GIF i aktuell mapp?','Synkronisera cachen för ALLA mappar? Tar bort föråldrade filer och återskapar saknade/gamla miniatyrer.','Synkronisera cachen för aktuell mapp? Tar bort föråldrade filer och återskapar saknade/gamla miniatyrer.','Fel vid rensning av cache','Fel vid synkronisering av cache','Fel vid skapande av hash','Fel','Cachen rensad.\nMappar: {dirs}\nMiniatyrer: {thumbs}\nGIF:ar: {gifs}','Synkroniseringen är klar.','{v} mappar besökta • miniatyrer: {c} skapade, {r} uppdaterade, {x} borttagna • GIF:ar: {g} uppdaterade, {y} borttagna','Synkroniserar cache…','Analyserar mappar och miniatyrer, vänta.','Klart','Ange lösenordet','Åtkomst nekad','Autentiseringsfel','Ange det nya lösenordet som ska omvandlas till en hash (sparas inte automatiskt):','Hash skapad och kopierad till urklipp:\n\n{hash}\n\nKlistra in den i ".config.json" under "auth_password".','inte tillgängliga','Språk','Automatiskt (webbläsare)','Mått','Kamera','Objektiv','Datum','Brännvidd','Bländare','Exponering','finns'],
    'da' => ['Dansk','Adgang påkrævet','Adgangskode','Gå ind','Forkert adgangskode','Hjem','Logget ind (ekstra funktioner aktiveret)','Ikke logget ind (kræves til ekstra funktioner)','Logget ind','Menu','Ryd cache','Slet miniaturer og GIF’er i den aktuelle mappe','Synkroniser cache','Synkroniser cachen med mappernes aktuelle indhold','Opret hash','Log ud','Log ind','Mapper','Fotos','Ingen billeder fundet.','Fotovisning','Luk visning (Esc)','Forrige foto (venstrepil)','Næste foto (højrepil)','Oplysninger og EXIF-data (i)','Annuller','Slet ALLE miniaturer og GIF’er i ALLE mapper?','Slet miniaturer og GIF i den aktuelle mappe?','Synkroniser cachen for ALLE mapper? Fjerner forældede filer og genskaber manglende/gamle miniaturer.','Synkroniser cachen for den aktuelle mappe? Fjerner forældede filer og genskaber manglende/gamle miniaturer.','Fejl ved rydning af cache','Fejl ved synkronisering af cache','Fejl ved oprettelse af hash','Fejl','Cache ryddet.\nMapper: {dirs}\nMiniaturer: {thumbs}\nGIF’er: {gifs}','Synkronisering fuldført.','{v} mapper besøgt • miniaturer: {c} oprettet, {r} opdateret, {x} fjernet • GIF’er: {g} opdateret, {y} fjernet','Synkroniserer cache…','Analyserer mapper og miniaturer, vent venligst.','Fuldført','Indtast adgangskoden','Adgang nægtet','Godkendelsesfejl','Indtast den nye adgangskode, der skal omdannes til en hash (gemmes ikke automatisk):','Hash oprettet og kopieret til udklipsholderen:\n\n{hash}\n\nIndsæt den i ".config.json" under "auth_password".','ikke tilgængelige','Sprog','Automatisk (browser)','Dimensioner','Kamera','Objektiv','Dato','Brændvidde','Blænde','Eksponering','til stede'],
    'no' => ['Norsk','Tilgang kreves','Passord','Gå inn','Feil passord','Hjem','Logget inn (ekstrafunksjoner aktivert)','Ikke logget inn (kreves for ekstrafunksjoner)','Logget inn','Meny','Tøm hurtigbuffer','Slett miniatyrbilder og GIF-er i gjeldende mappe','Synkroniser hurtigbuffer','Synkroniser hurtigbufferen med mappenes gjeldende innhold','Opprett hash','Logg ut','Logg inn','Mapper','Bilder','Ingen bilder funnet.','Bildeviser','Lukk visning (Esc)','Forrige bilde (venstrepil)','Neste bilde (høyrepil)','Informasjon og EXIF-data (i)','Avbryt','Slette ALLE miniatyrbilder og GIF-er i ALLE mapper?','Slette miniatyrbilder og GIF i gjeldende mappe?','Synkronisere hurtigbufferen for ALLE mapper? Fjerner utdaterte filer og lager manglende/gamle miniatyrbilder på nytt.','Synkronisere hurtigbufferen for gjeldende mappe? Fjerner utdaterte filer og lager manglende/gamle miniatyrbilder på nytt.','Feil ved tømming av hurtigbuffer','Feil ved synkronisering av hurtigbuffer','Feil ved oppretting av hash','Feil','Hurtigbuffer tømt.\nMapper: {dirs}\nMiniatyrbilder: {thumbs}\nGIF-er: {gifs}','Synkroniseringen er fullført.','{v} mapper besøkt • miniatyrbilder: {c} opprettet, {r} oppdatert, {x} fjernet • GIF-er: {g} oppdatert, {y} fjernet','Synkroniserer hurtigbuffer…','Analyserer mapper og miniatyrbilder, vent.','Fullført','Skriv inn passordet','Tilgang nektet','Autentiseringsfeil','Skriv inn det nye passordet som skal gjøres om til en hash (lagres ikke automatisk):','Hash opprettet og kopiert til utklippstavlen:\n\n{hash}\n\nLim den inn i ".config.json" under "auth_password".','ikke tilgjengelig','Språk','Automatisk (nettleser)','Dimensjoner','Kamera','Objektiv','Dato','Brennvidde','Blenderåpning','Eksponering','til stede'],
    'is' => ['Íslenska','Aðgangs krafist','Lykilorð','Inn','Rangt lykilorð','Heim','Skráður inn (aukaeiginleikar virkir)','Ekki skráður inn (nauðsynlegt fyrir aukaeiginleika)','Skráður inn','Valmynd','Hreinsa skyndiminni','Eyða smámyndum og GIF-um í núverandi möppu','Samstilla skyndiminni','Samstilla skyndiminni við núverandi innihald mappa','Búa til hass','Skrá út','Skrá inn','Möppur','Myndir','Engar myndir fundust.','Myndskoðari','Loka skoðara (Esc)','Fyrri mynd (vinstri ör)','Næsta mynd (hægri ör)','Upplýsingar og EXIF-gögn (i)','Hætta við','Eyða ÖLLUM smámyndum og GIF-um í ÖLLUM möppum?','Eyða smámyndum og GIF í núverandi möppu?','Samstilla skyndiminni fyrir ALLAR möppur? Fjarlægir úrelt skjöl og býr til vantandi/gamlar smámyndir aftur.','Samstilla skyndiminni fyrir núverandi möppu? Fjarlægir úrelt skjöl og býr til vantandi/gamlar smámyndir aftur.','Villa við hreinsun skyndiminnis','Villa við samstillingu skyndiminnis','Villa við gerð hass','Villa','Skyndiminni hreinsað.\nMöppur: {dirs}\nSmámyndir: {thumbs}\nGIF: {gifs}','Samstillingu lokið.','{v} möppur heimsóttar • smámyndir: {c} búnar til, {r} uppfærðar, {x} fjarlægðar • GIF: {g} uppfærð, {y} fjarlægð','Samstilli skyndiminni…','Greini möppur og smámyndir, vinsamlegast bíðið.','Lokið','Sláðu inn lykilorðið','Aðgangi hafnað','Auðkenningarvilla','Sláðu inn nýja lykilorðið sem á að breyta í hass (það er ekki vistað sjálfkrafa):','Hass búið til og afritað á klippiborð:\n\n{hash}\n\nLímdu það inn í ".config.json" undir "auth_password".','ekki tiltækt','Tungumál','Sjálfvirkt (vafri)','Stærð','Myndavél','Linsa','Dagsetning','Brennivídd','Ljósop','Lýsing','til staðar'],
    'et' => ['Eesti','Vajalik on juurdepääs','Parool','Sisene','Vale parool','Avaleht','Sisse logitud (lisafunktsioonid lubatud)','Pole sisse logitud (vajalik lisafunktsioonide jaoks)','Sisse logitud','Menüü','Tühjenda vahemälu','Kustuta praeguse kausta pisipildid ja GIF-id','Sünkrooni vahemälu','Sünkrooni vahemälu kaustade praeguse sisuga','Loo räsi','Logi välja','Logi sisse','Kaustad','Fotod','Pilte ei leitud.','Fotovaatur','Sulge vaatur (Esc)','Eelmine foto (vasaknool)','Järgmine foto (paremnool)','Teave ja EXIF-andmed (i)','Tühista','Kas kustutada KÕIGI kaustade KÕIK pisipildid ja GIF-id?','Kas kustutada praeguse kausta pisipildid ja GIF?','Kas sünkroonida vahemälu KÕIGI kaustade jaoks? Eemaldab aegunud failid ja loob puuduvad/vanad pisipildid uuesti.','Kas sünkroonida vahemälu praeguse kausta jaoks? Eemaldab aegunud failid ja loob puuduvad/vanad pisipildid uuesti.','Viga vahemälu tühjendamisel','Viga vahemälu sünkroonimisel','Viga räsi loomisel','Viga','Vahemälu tühjendatud.\nKaustad: {dirs}\nPisipildid: {thumbs}\nGIF-id: {gifs}','Sünkroonimine lõpetatud.','Külastatud kaustu: {v} • pisipildid: {c} loodud, {r} uuendatud, {x} eemaldatud • GIF-id: {g} uuendatud, {y} eemaldatud','Vahemälu sünkroonimine…','Analüüsin kaustu ja pisipilte, palun oota.','Valmis','Sisesta parool','Juurdepääs keelatud','Autentimisviga','Sisesta uus parool, millest tehakse räsi (seda ei salvestata automaatselt):','Räsi loodud ja kopeeritud lõikelauale:\n\n{hash}\n\nKleebi see faili ".config.json" välja "auth_password".','pole saadaval','Keel','Automaatne (brauser)','Mõõtmed','Kaamera','Objektiiv','Kuupäev','Fookuskaugus','Ava','Säriaeg','olemas'],
    'lv' => ['Latviešu','Nepieciešama piekļuve','Parole','Ieiet','Nepareiza parole','Sākums','Pieteicies (papildfunkcijas iespējotas)','Nav pieteicies (nepieciešams papildfunkcijām)','Pieteicies','Izvēlne','Notīrīt kešatmiņu','Dzēst pašreizējās mapes sīktēlus un GIF failus','Sinhronizēt kešatmiņu','Sinhronizēt kešatmiņu ar mapju pašreizējo saturu','Izveidot jaucējkodu','Iziet','Pieteikties','Mapes','Fotoattēli','Attēli nav atrasti.','Fotoattēlu skatītājs','Aizvērt skatītāju (Esc)','Iepriekšējais fotoattēls (kreisā bulta)','Nākamais fotoattēls (labā bulta)','Informācija un EXIF dati (i)','Atcelt','Dzēst VISUS sīktēlus un GIF failus VISĀS mapēs?','Dzēst pašreizējās mapes sīktēlus un GIF failu?','Sinhronizēt kešatmiņu VISĀM mapēm? Noņem novecojušus failus un no jauna izveido trūkstošos/vecos sīktēlus.','Sinhronizēt kešatmiņu pašreizējai mapei? Noņem novecojušus failus un no jauna izveido trūkstošos/vecos sīktēlus.','Kļūda, tīrot kešatmiņu','Kļūda, sinhronizējot kešatmiņu','Kļūda, veidojot jaucējkodu','Kļūda','Kešatmiņa notīrīta.\nMapes: {dirs}\nSīktēli: {thumbs}\nGIF: {gifs}','Sinhronizācija pabeigta.','Apmeklētas mapes: {v} • sīktēli: {c} izveidoti, {r} atjaunināti, {x} noņemti • GIF: {g} atjaunināti, {y} noņemti','Sinhronizē kešatmiņu…','Analizē mapes un sīktēlus, lūdzu, uzgaidiet.','Pabeigts','Ievadiet paroli','Piekļuve liegta','Autentifikācijas kļūda','Ievadiet jauno paroli, ko pārvērst jaucējkodā (tā netiks automātiski saglabāta):','Jaucējkods izveidots un nokopēts starpliktuvē:\n\n{hash}\n\nIelīmējiet to failā ".config.json" laukā "auth_password".','nav pieejami','Valoda','Automātiski (pārlūks)','Izmēri','Kamera','Objektīvs','Datums','Fokusa attālums','Diafragma','Ekspozīcija','ir'],
    'lt' => ['Lietuvių','Reikalinga prieiga','Slaptažodis','Įeiti','Neteisingas slaptažodis','Pradžia','Prisijungta (papildomos funkcijos įjungtos)','Neprisijungta (reikalinga papildomoms funkcijoms)','Prisijungta','Meniu','Išvalyti talpyklą','Ištrinti dabartinio aplanko miniatiūras ir GIF','Sinchronizuoti talpyklą','Sinchronizuoti talpyklą su dabartiniu aplankų turiniu','Sukurti maišą','Atsijungti','Prisijungti','Aplankai','Nuotraukos','Vaizdų nerasta.','Nuotraukų peržiūra','Uždaryti peržiūrą (Esc)','Ankstesnė nuotrauka (kairė rodyklė)','Kita nuotrauka (dešinė rodyklė)','Informacija ir EXIF duomenys (i)','Atšaukti','Ištrinti VISAS miniatiūras ir GIF iš VISŲ aplankų?','Ištrinti dabartinio aplanko miniatiūras ir GIF?','Sinchronizuoti talpyklą VISIEMS aplankams? Pašalina pasenusius failus ir iš naujo sukuria trūkstamas/senas miniatiūras.','Sinchronizuoti talpyklą dabartiniam aplankui? Pašalina pasenusius failus ir iš naujo sukuria trūkstamas/senas miniatiūras.','Klaida valant talpyklą','Klaida sinchronizuojant talpyklą','Klaida kuriant maišą','Klaida','Talpykla išvalyta.\nAplankai: {dirs}\nMiniatiūros: {thumbs}\nGIF: {gifs}','Sinchronizavimas baigtas.','Aplankyta aplankų: {v} • miniatiūros: {c} sukurta, {r} atnaujinta, {x} pašalinta • GIF: {g} atnaujinta, {y} pašalinta','Sinchronizuojama talpykla…','Analizuojami aplankai ir miniatiūros, palaukite.','Baigta','Įveskite slaptažodį','Prieiga uždrausta','Autentifikavimo klaida','Įveskite naują slaptažodį, kuris bus paverstas maišos reikšme (automatiškai neišsaugoma):','Maiša sukurta ir nukopijuota į iškarpinę:\n\n{hash}\n\nĮklijuokite ją į „.config.json“ laukelyje „auth_password“.','nepasiekiama','Kalba','Automatinė (naršyklė)','Matmenys','Fotoaparatas','Objektyvas','Data','Židinio nuotolis','Diafragma','Išlaikymas','yra'],
    'ga' => ['Gaeilge','Teastaíonn rochtain','Pasfhocal','Isteach','Pasfhocal mícheart','Baile','Logáilte isteach (gnéithe breise cumasaithe)','Gan logáil isteach (riachtanach do ghnéithe breise)','Logáilte isteach','Roghchlár','Glan an taisce','Scrios mionsamhlacha agus GIFanna an fhillteáin reatha','Sioncronaigh an taisce','Sioncronaigh an taisce le hábhar reatha na bhfillteán','Cruthaigh hais','Logáil amach','Logáil isteach','Fillteáin','Grianghraif','Níor aimsíodh íomhánna.','Amharcán grianghraf','Dún an t-amharcán (Esc)','An grianghraf roimhe (saighead chlé)','An chéad ghrianghraf eile (saighead dheas)','Eolas agus sonraí EXIF (i)','Cealaigh','Scrios GACH mionsamhail agus GIF i GACH fillteán?','Scrios mionsamhlacha agus GIF an fhillteáin reatha?','Sioncronaigh an taisce do GACH fillteán? Baintear comhaid atá as dáta agus athghinntear mionsamhlacha atá ar iarraidh/sean.','Sioncronaigh an taisce don fhillteán reatha? Baintear comhaid atá as dáta agus athghinntear mionsamhlacha atá ar iarraidh/sean.','Earráid agus an taisce á glanadh','Earráid agus an taisce á sioncronú','Earráid agus hais á cruthú','Earráid','Taisce glanta.\nFillteáin: {dirs}\nMionsamhlacha: {thumbs}\nGIFanna: {gifs}','Sioncronú críochnaithe.','{v} fillteán cuairtithe • mionsamhlacha: {c} cruthaithe, {r} nuashonraithe, {x} bainte • GIFanna: {g} nuashonraithe, {y} bainte','Taisce á sioncronú…','Fillteáin agus mionsamhlacha á n-anailísiú, fan go fóill.','Críochnaithe','Cuir isteach an pasfhocal','Rochtain diúltaithe','Earráid fíordheimhnithe','Cuir isteach an pasfhocal nua le tiontú ina hais (ní shábhálfar go huathoibríoch é):','Hais cruthaithe agus cóipeáilte chuig an ghearrthaisce:\n\n{hash}\n\nPáisteáil é in ".config.json" faoi "auth_password".','níl ar fáil','Teanga','Uathoibríoch (brabhsálaí)','Toisí','Ceamara','Lionsa','Dáta','Fad fócasach','Cró','Nochtadh','i láthair'],
    'mt' => ['Malti','Meħtieġ aċċess','Password','Idħol','Password ħażina','Home','Illoggjat (funzjonijiet żejda attivati)','Mhux illoggjat (meħtieġ għal funzjonijiet żejda)','Illoggjat','Menu','Naddaf il-cache','Ħassar thumbnails u GIFs tal-folder kurrenti','Sinkronizza l-cache','Sinkronizza l-cache mal-kontenut kurrenti tal-folders','Oħloq hash','Oħroġ','Idħol','Folders','Ritratti','Ma nstabu l-ebda immaġini.','Viewer tar-ritratti','Agħlaq il-viewer (Esc)','Ritratt preċedenti (vleġġa lemin)','Ritratt li jmiss (vleġġa xellug)','Informazzjoni u dejta EXIF (i)','Ikkanċella','Ħassar it-thumbnails u l-GIFs KOLLHA tal-folders KOLLHA?','Ħassar it-thumbnails u l-GIF tal-folder kurrenti?','Sinkronizza l-cache għall-folders KOLLHA? Ineħħi fajls skaduti u terġa’ toħloq thumbnails neqsin/qodma.','Sinkronizza l-cache għall-folder kurrenti? Ineħħi fajls skaduti u terġa’ toħloq thumbnails neqsin/qodma.','Żball fit-tindif tal-cache','Żball fis-sinkronizzazzjoni tal-cache','Żball fil-ħolqien tal-hash','Żball','Cache indifa.\nFolders: {dirs}\nThumbnails: {thumbs}\nGIFs: {gifs}','Sinkronizzazzjoni lesta.','{v} folders żurati • thumbnails: {c} maħluqa, {r} aġġornati, {x} imneħħija • GIFs: {g} aġġornati, {y} imneħħija','Qed tiġi sinkronizzata l-cache…','Qed jiġu analizzati l-folders u t-thumbnails, stenna.','Lest','Daħħal il-password','Aċċess miċħud','Żball ta’ awtentikazzjoni','Daħħal il-password il-ġdida biex tinbidel f’hash (ma tinħażinx awtomatikament):','Hash maħluq u kopjat fil-clipboard:\n\n{hash}\n\nIwweġġgu f’".config.json" taħt "auth_password".','mhux disponibbli','Lingwa','Awtomatiku (browser)','Dimensjonijiet','Kamera','Lenti','Data','Tul fokali','Apertura','Espożizzjoni','preżenti'],
    'ca' => ['Català','Accés necessari','Contrasenya','Entra','Contrasenya incorrecta','Inici','Sessió iniciada (funcions extra activades)','Sense sessió (necessària per a funcions extra)','Sessió iniciada','Menú','Buida la memòria cau','Elimina les miniatures i els GIF de la carpeta actual','Sincronitza la memòria cau','Sincronitza la memòria cau amb el contingut actual de les carpetes','Crea un hash','Tanca la sessió','Inicia la sessió','Carpetes','Fotos','No s’ha trobat cap imatge.','Visor de fotos','Tanca el visor (Esc)','Foto anterior (fletxa esquerra)','Foto següent (fletxa dreta)','Informació i dades EXIF (i)','Cancel·la','Voleu eliminar TOTES les miniatures i els GIF de TOTES les carpetes?','Voleu eliminar les miniatures i el GIF de la carpeta actual?','Voleu sincronitzar la memòria cau a TOTES les carpetes? Elimina fitxers obsolets i regenera les miniatures que falten/antigues.','Voleu sincronitzar la memòria cau a la carpeta actual? Elimina fitxers obsolets i regenera les miniatures que falten/antigues.','Error en buidar la memòria cau','Error en sincronitzar la memòria cau','Error en generar el hash','Error','Memòria cau buidada.\nCarpetes: {dirs}\nMiniatures: {thumbs}\nGIF: {gifs}','Sincronització completada.','{v} carpetes visitades • miniatures: {c} creades, {r} actualitzades, {x} eliminades • GIF: {g} actualitzats, {y} eliminats','Sincronitzant la memòria cau…','Analitzant carpetes i miniatures, espereu.','Completat','Introduïu la contrasenya','Accés denegat','Error d’autenticació','Introduïu la nova contrasenya per convertir-la en hash (no es desarà automàticament):','Hash generat i copiat al porta-retalls:\n\n{hash}\n\nEnganxeu-lo a «.config.json» a «auth_password».','no disponibles','Idioma','Automàtic (navegador)','Dimensions','Càmera','Objectiu','Data','Distància focal','Obertura','Exposició','present'],
    'uk' => ['Українська','Потрібен доступ','Пароль','Увійти','Невірний пароль','Головна','Виконано вхід (додаткові функції увімкнено)','Вхід не виконано (потрібен для додаткових функцій)','Виконано вхід','Меню','Очистити кеш','Видалити мініатюри та GIF поточної папки','Синхронізувати кеш','Синхронізувати кеш із поточним вмістом папок','Створити хеш','Вийти','Увійти','Папки','Фото','Зображень не знайдено.','Переглядач фото','Закрити переглядач (Esc)','Попереднє фото (стрілка вліво)','Наступне фото (стрілка вправо)','Інформація та дані EXIF (i)','Скасувати','Видалити УСІ мініатюри та GIF з УСІХ папок?','Видалити мініатюри та GIF поточної папки?','Синхронізувати кеш для УСІХ папок? Видаляє застарілі файли та заново створює відсутні/старі мініатюри.','Синхронізувати кеш для поточної папки? Видаляє застарілі файли та заново створює відсутні/старі мініатюри.','Помилка очищення кешу','Помилка синхронізації кешу','Помилка створення хеша','Помилка','Кеш очищено.\nПапки: {dirs}\nМініатюри: {thumbs}\nGIF: {gifs}','Синхронізацію завершено.','Відвідано папок: {v} • мініатюри: {c} створено, {r} оновлено, {x} видалено • GIF: {g} оновлено, {y} видалено','Синхронізація кешу…','Аналіз папок і мініатюр, зачекайте.','Завершено','Введіть пароль','Доступ заборонено','Помилка автентифікації','Введіть новий пароль для перетворення на хеш (автоматично не зберігатиметься):','Хеш створено й скопійовано в буфер обміну:\n\n{hash}\n\nВставте його в «.config.json» у «auth_password».','недоступно','Мова','Автоматично (браузер)','Розміри','Камера','Об’єктив','Дата','Фокусна відстань','Діафрагма','Витримка','є'],
    'ru' => ['Русский','Требуется доступ','Пароль','Войти','Неверный пароль','Главная','Вход выполнен (дополнительные функции включены)','Вход не выполнен (требуется для дополнительных функций)','Вход выполнен','Меню','Очистить кэш','Удалить миниатюры и GIF текущей папки','Синхронизировать кэш','Синхронизировать кэш с текущим содержимым папок','Создать хеш','Выйти','Войти','Папки','Фото','Изображения не найдены.','Просмотр фото','Закрыть просмотр (Esc)','Предыдущее фото (стрелка влево)','Следующее фото (стрелка вправо)','Информация и данные EXIF (i)','Отмена','Удалить ВСЕ миниатюры и GIF из ВСЕХ папок?','Удалить миниатюры и GIF текущей папки?','Синхронизировать кэш для ВСЕХ папок? Удаляет устаревшие файлы и заново создаёт отсутствующие/старые миниатюры.','Синхронизировать кэш для текущей папки? Удаляет устаревшие файлы и заново создаёт отсутствующие/старые миниатюры.','Ошибка очистки кэша','Ошибка синхронизации кэша','Ошибка создания хеша','Ошибка','Кэш очищен.\nПапки: {dirs}\nМиниатюры: {thumbs}\nGIF: {gifs}','Синхронизация завершена.','Просмотрено папок: {v} • миниатюры: {c} создано, {r} обновлено, {x} удалено • GIF: {g} обновлено, {y} удалено','Синхронизация кэша…','Анализ папок и миниатюр, подождите.','Завершено','Введите пароль','Доступ запрещён','Ошибка аутентификации','Введите новый пароль для преобразования в хеш (автоматически не сохраняется):','Хеш создан и скопирован в буфер обмена:\n\n{hash}\n\nВставьте его в «.config.json» в «auth_password».','недоступны','Язык','Автоматически (браузер)','Размеры','Камера','Объектив','Дата','Фокусное расстояние','Диафрагма','Выдержка','есть'],
    'sr' => ['Српски','Потребан је приступ','Лозинка','Уђи','Погрешна лозинка','Почетна','Пријављен (додатне функције омогућене)','Није пријављен (потребно за додатне функције)','Пријављен','Мени','Очисти кеш','Обриши сличице и GIF-ове тренутног фолдера','Синхронизуј кеш','Синхронизуј кеш са тренутним садржајем фолдера','Направи хеш','Одјави се','Пријави се','Фолдери','Фотографије','Нису пронађене слике.','Прегледач фотографија','Затвори прегледач (Esc)','Претходна фотографија (стрелица лево)','Следећа фотографија (стрелица десно)','Информације и EXIF подаци (i)','Откажи','Обрисати СВЕ сличице и GIF-ове из СВИХ фолдера?','Обрисати сличице и GIF тренутног фолдера?','Синхронизовати кеш за СВЕ фолдере? Уклања застареле фајлове и поново прави недостајуће/старе сличице.','Синхронизовати кеш за тренутни фолдер? Уклања застареле фајлове и поново прави недостајуће/старе сличице.','Грешка при чишћењу кеша','Грешка при синхронизацији кеша','Грешка при прављењу хеша','Грешка','Кеш је очишћен.\nФолдери: {dirs}\nСличице: {thumbs}\nGIF: {gifs}','Синхронизација је завршена.','Посећено фолдера: {v} • сличице: {c} направљено, {r} ажурирано, {x} уклоњено • GIF: {g} ажурирано, {y} уклоњено','Синхронизација кеша…','Анализа фолдера и сличица, сачекајте.','Завршено','Унесите лозинку','Приступ одбијен','Грешка при аутентификацији','Унесите нову лозинку за претварање у хеш (неће бити аутоматски сачувана):','Хеш је направљен и копиран у клипборд:\n\n{hash}\n\nЛепите га у „.config.json“ у „auth_password“.','није доступно','Језик','Аутоматски (прегледач)','Димензије','Камера','Објектив','Датум','Жижна даљина','Бленда','Експозиција','присутно'],
    'sq' => ['Shqip','Kërkohet qasje','Fjalëkalimi','Hyr','Fjalëkalim i gabuar','Kreu','I identifikuar (funksionet shtesë të aktivizuara)','I paidentifikuar (kërkohet për funksionet shtesë)','I identifikuar','Menyja','Pastro cache','Fshi miniaturat dhe GIF-et e dosjes aktuale','Sinkronizo cache','Sinkronizo cache me përmbajtjen aktuale të dosjeve','Krijo hash','Dil','Hyr','Dosjet','Fotot','Nuk u gjet asnjë imazh.','Shikuesi i fotove','Mbyll shikuesin (Esc)','Foto e mëparshme (shigjeta majtas)','Foto tjetër (shigjeta djathtas)','Informacione dhe të dhëna EXIF (i)','Anulo','Të fshihen TË GJITHA miniaturat dhe GIF-et në TË GJITHA dosjet?','Të fshihen miniaturat dhe GIF-i i dosjes aktuale?','Të sinkronizohet cache për TË GJITHA dosjet? Heq skedarët e vjetëruar dhe rigjeneron miniaturat që mungojnë/të vjetra.','Të sinkronizohet cache për dosjen aktuale? Heq skedarët e vjetëruar dhe rigjeneron miniaturat që mungojnë/të vjetra.','Gabim gjatë pastrimit të cache','Gabim gjatë sinkronizimit të cache','Gabim gjatë krijimit të hash','Gabim','Cache u pastrua.\nDosje: {dirs}\nMiniatura: {thumbs}\nGIF: {gifs}','Sinkronizimi përfundoi.','{v} dosje të vizituara • miniatura: {c} të krijuara, {r} të përditësuara, {x} të hequra • GIF: {g} të përditësuara, {y} të hequra','Po sinkronizohet cache…','Po analizohen dosjet dhe miniaturat, prisni.','Përfundoi','Shkruani fjalëkalimin','Qasja u refuzua','Gabim autentikimi','Shkruani fjalëkalimin e ri që do të kthehet në hash (nuk ruhet automatikisht):','Hash u krijua dhe u kopjua në clipboard:\n\n{hash}\n\nNgjitni te ".config.json" te "auth_password".','nuk disponohen','Gjuha','Automatik (shfletuesi)','Përmasat','Kamera','Objektivi','Data','Gjatësia fokale','Hapja','Ekspozimi','e pranishme'],
    'mk' => ['Македонски','Потребен е пристап','Лозинка','Влези','Погрешна лозинка','Почетна','Најавен (дополнителните функции се овозможени)','Ненајавен (потребно за дополнителни функции)','Најавен','Мени','Исчисти кеш','Избриши ги сликичките и GIF-овите од тековната папка','Синхронизирај кеш','Синхронизирај го кешот со тековната содржина на папките','Создај хеш','Одјави се','Најави се','Папки','Фотографии','Не се пронајдени слики.','Прегледувач на фотографии','Затвори го прегледувачот (Esc)','Претходна фотографија (стрелка лево)','Следна фотографија (стрелка десно)','Информации и EXIF податоци (i)','Откажи','Да се избришат СИТЕ сликички и GIF-ови од СИТЕ папки?','Да се избришат сликичките и GIF-от од тековната папка?','Да се синхронизира кешот за СИТЕ папки? Отстранува застарени датотеки и повторно создава сликички што недостасуваат/се стари.','Да се синхронизира кешот за тековната папка? Отстранува застарени датотеки и повторно создава сликички што недостасуваат/се стари.','Грешка при чистење на кешот','Грешка при синхронизација на кешот','Грешка при создавање хеш','Грешка','Кешот е исчистен.\nПапки: {dirs}\nСликички: {thumbs}\nGIF: {gifs}','Синхронизацијата е завршена.','Посетени папки: {v} • сликички: {c} создадени, {r} ажурирани, {x} отстранети • GIF: {g} ажурирани, {y} отстранети','Синхронизација на кешот…','Се анализираат папките и сликичките, почекајте.','Завршено','Внесете ја лозинката','Пристапот е одбиен','Грешка при автентикација','Внесете ја новата лозинка за претворање во хеш (нема автоматски да се зачува):','Хешот е создаден и копиран во привремената меморија:\n\n{hash}\n\nЛепете го во „.config.json“ во „auth_password“.','не е достапно','Јазик','Автоматски (прелистувач)','Димензии','Камера','Објектив','Датум','Фокусна должина','Бленда','Експозиција','присутно'],
    ];
}

function mpg_lang_resolve(string $pref): string {
    $data = mpg_i18n_data();
    if ($pref !== 'auto' && isset($data[$pref])) return $pref;
    $cands = [];
    foreach (explode(',', (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) as $i => $part) {
        $bits = explode(';', trim($part));
        $tag = strtolower(trim($bits[0]));
        $q = 1.0;
        if (isset($bits[1]) && preg_match('/q=([0-9.]+)/', $bits[1], $m)) $q = (float)$m[1];
        if ($tag === '' || $tag === '*' || $q <= 0) continue;
        $cands[] = [$q, $i, $tag];
    }
    usort($cands, fn($a, $b) => ($b[0] <=> $a[0]) ?: ($a[1] <=> $b[1]));
    foreach ($cands as $c) {
        $base = explode('-', $c[2])[0];
        if ($base === 'nb' || $base === 'nn') $base = 'no';
        if (isset($data[$base])) return $base;
    }
    return 'en';
}
function mpg_lang_langs(): array {
    $out = [];
    foreach (mpg_i18n_data() as $code => $row) $out[$code] = $row[0];
    return $out;
}
// Stringhe nella lingua corrente (fallback inglese), con "\n" letterale convertito in newline
function mpg_i18n_strings(string $lang): array {
    $data = mpg_i18n_data();
    $keys = mpg_i18n_keys();
    $en = array_combine($keys, array_slice($data['en'], 1));
    $cur = isset($data[$lang]) ? array_combine($keys, array_slice($data[$lang], 1)) : $en;
    $out = [];
    foreach ($keys as $k) {
        $v = $cur[$k] !== '' ? $cur[$k] : $en[$k];
        $out[$k] = str_replace('\n', "\n", $v);
    }
    return $out;
}
$cfg['language'] = (string)($cfg['language'] ?? 'auto');
if ($cfg['language'] !== 'auto' && !isset(mpg_i18n_data()[$cfg['language']])) { $cfg['language'] = 'auto'; }
$LANG = mpg_lang_resolve($cfg['language']);
$I18N = mpg_i18n_strings($LANG);
function t(string $key, array $params = []): string {
    global $I18N;
    $s = $I18N[$key] ?? $key;
    foreach ($params as $k => $v) $s = str_replace('{' . $k . '}', (string)$v, $s);
    return $s;
}

// === SESSIONE E AUTENTICAZIONE SEMPLICE ===
// SECURITY: Enforce cookie-only sessions with HttpOnly, SameSite, and conditional Secure flags
if (session_status() !== PHP_SESSION_ACTIVE) {
    @ini_set('session.use_only_cookies', '1');
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? null) == 443)
        || ((isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    @session_start();
}
if (empty($_SESSION['csrf'])) {
    try { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
    catch (Throwable $e) { $_SESSION['csrf'] = bin2hex(openssl_random_pseudo_bytes(16)); }
}
$CSRF_TOKEN = $_SESSION['csrf'];
$SESSION_KEY = 'mpg_auth';
if (isset($_GET['logout'])) {
    unset($_SESSION[$SESSION_KEY]);
    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_regenerate_id(true);
    }
    header('Location: ' . $self); exit;
}
function auth_ok() {
    global $cfg, $SESSION_KEY;
    if (!isset($cfg['auth_mode'])) return true;
    if ((int)$cfg['auth_mode'] === 0) return true;
    return !empty($_SESSION[$SESSION_KEY]);
}
function try_login($pass) {
    global $cfg, $SESSION_KEY;
    $stored = (string)$cfg['auth_password'];
    if ($stored === '') return false;
    $ok = false;
    // supporta hash bcrypt o testo in chiaro
    if (preg_match('/^\$2[aby]\$/', $stored)) {
        $ok = password_verify($pass, $stored);
    } else {
        $ok = hash_equals($stored, $pass);
    }
    if ($ok) {
        // SECURITY: Regenerate session ID and CSRF token on privilege escalation to prevent session fixation attacks
        @session_regenerate_id(true);
        try { $_SESSION['csrf'] = bin2hex(random_bytes(16)); } catch (Throwable $e) {}
        $_SESSION[$SESSION_KEY] = 1;
        return true;
    }
    return false;
}

// Debug logger

function dbg_log($data) {
    global $configDir, $debugFlag;
    if (empty($debugFlag)) return;
    $line = '[' . date('c') . '] ' . json_encode($data, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . PHP_EOL;
    @file_put_contents($configDir . '/.debug.log', $line, FILE_APPEND);
}

// Ritorna true se il file ha estensione immagine supportata (case-insensitive)
function is_image_ext($path){
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return in_array($ext, $GLOBALS['imgExt'], true);
}
function image_sort_timestamp(string $path): int {
    $mtime = @filemtime($path);
    $fallback = $mtime === false ? 0 : $mtime;
    if (!function_exists('exif_read_data')) return $fallback;

    $exif = @exif_read_data($path, null, true, false);
    if (!is_array($exif)) return $fallback;
    foreach ([['EXIF','DateTimeOriginal'],['EXIF','DateTimeDigitized'],['IFD0','DateTime']] as [$section, $key]) {
        $value = $exif[$section][$key] ?? null;
        if (!is_string($value)) continue;
        $date = DateTimeImmutable::createFromFormat('!Y:m:d H:i:s', substr(trim($value), 0, 19));
        if ($date !== false) return $date->getTimestamp();
    }
    return $fallback;
}
// Lista solo le immagini supportate nella cartella (primo livello), ordinandole in modo naturale
function list_images($dirAbs){
    $out = [];
    $scan = @scandir($dirAbs) ?: [];
    foreach ($scan as $e){
        if ($e === '.' || $e === '..') continue;
        if ($e[0] === '.') continue;                // nascoste escluse
        $p = $dirAbs . DIRECTORY_SEPARATOR . $e;
        if (!is_file($p)) continue;
        if (!is_image_ext($p)) continue;
        $out[] = $p;
    }
    natsort($out);
    return array_values($out);
}

$__LAST_THUMB_ERR = null;
function img_dims($path) {
    $s = @getimagesize($path);
    return $s ? ['w'=>$s[0]??0,'h'=>$s[1]??0,'mime'=>$s['mime']??''] : null;
}
function rel($abs) {
    // Converte un path assoluto del filesystem in path URL relativo alla directory dello script
    $abs = str_replace('\\', '/', $abs);
    $baseFs = rtrim(str_replace('\\', '/', realpath($GLOBALS['baseDir'])), '/');
    $baseUrl = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    // SECURITY: Enforce strict boundary matching to prevent prefix confusion with sibling paths
    if ($abs === $baseFs || strpos($abs, $baseFs . '/') === 0) {
        $suffix = ltrim(substr($abs, strlen($baseFs)), '/');
        // Se lo script è in root, non aggiungere doppio slash
        if ($baseUrl === '' || $baseUrl === '/') {
            return '/' . $suffix;
        }
        return $baseUrl . '/' . $suffix;
    }
    // Fallback: basename come ultima risorsa
    return '/' . basename($abs);
}

// URL relativo con percent-encoding dei segmenti (gestisce spazi e caratteri speciali)
function rel_url($abs) {
    $p = rel($abs);
    $parts = explode('/', $p);
    $out = [];
    foreach ($parts as $i => $seg) {
        if ($seg === '' && $i === 0) { $out[] = ''; continue; } // leading slash
        $out[] = rawurlencode($seg);
    }
    return implode('/', $out) ?: '/';
}

// Nome file thumbnail con prefisso nascosto, sempre .jpg
function thumb_name($origName) {
    // Thumbs sempre in JPEG: normalizza estensione a .jpg
    global $prefThumb;
    $base = preg_replace('/\.[^.]+$/', '', (string)$origName);
    return $prefThumb . $base . '.jpg';
}

$thumbWidth = $cfg['thumb_make'];        // lato lungo thumbnail generate

// === NAVIGAZIONE CARTELLE ===
$reqDir = isset($_GET['dir']) ? trim($_GET['dir']) : '';
// normalizza separatori
$reqDir = str_replace(['\\'], '/', $reqDir);
$reqDir = ltrim($reqDir, '/');
// blocca risalite
if (strpos($reqDir, '..') !== false) { $reqDir = ''; }
$currentAbs = realpath($baseDir . ($reqDir ? '/' . $reqDir : '')) ?: $baseDir;
// SECURITY: Enforce subtree boundary with trailing separator to prevent path prefix confusion (e.g. /app vs /app_secret)
$realBaseDir = rtrim(realpath($baseDir), DIRECTORY_SEPARATOR);
if ($currentAbs !== $realBaseDir && strpos($currentAbs, $realBaseDir . DIRECTORY_SEPARATOR) !== 0) { $currentAbs = $baseDir; $reqDir = ''; }
$relDir = trim(str_replace(realpath($baseDir), '', $currentAbs), DIRECTORY_SEPARATOR);
$relDir = str_replace(DIRECTORY_SEPARATOR, '/', $relDir);
// blocca l'accesso a cartelle escluse
if ($relDir) {
    $segs = explode('/', $relDir);
    $acc = [];
    foreach ($segs as $seg) {
        if (is_excluded_dir($seg, implode('/', array_merge($acc, [$seg])))) {
            // se uno dei segmenti è escluso, torna alla root
            $currentAbs = $baseDir;
            $relDir = '';
            break;
        }
        $acc[] = $seg;
    }
}
$isRoot = ($relDir === '' || $relDir === false);

// Directory thumbs per cartella corrente, mirroring struttura
$thumbDirForView = $isRoot ? $thumbsRoot : ($thumbsRoot . '/' . implode('/', array_map(fn($seg) => $prefFolder.$seg, explode('/', $relDir))));
if (!is_dir($thumbDirForView)) { @mkdir($thumbDirForView, 0755, true); }

// GIF di anteprima per cartelle: salviamo come .folder.gif dentro la cartella thumbs corrispondente
$folderGifName = $prefThumb . 'folder.gif';

// Progress file per GIF cartella
function folder_gif_progress_path($dirRel, $thumbsRoot, $prefThumb) {
    // Usa /tmp per progressi per evitare cache/lag su FS remoti
    $safe = $dirRel === '' ? '__root__' : str_replace(['\\','/'], '__', $dirRel);
    $dir  = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'mpg_progress';
    if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
    return $dir . DIRECTORY_SEPARATOR . $prefThumb . $safe . '.json';
}

// SECURITY: Helper per verificare se una cartella o percorso va escluso (inclusi segmenti antenati e cartelle nascoste)
function is_excluded_dir($entryName, $relativePath = '') {
    global $cfg;
    if ($entryName === '' || $entryName[0] === '.') return true;
    // SECURITY: Ensure no segment in relative path is hidden or inside an excluded directory to prevent path traversal bypasses
    $rel = str_replace(['\\'], '/', trim($relativePath, '/'));
    if ($rel !== '') {
        foreach (explode('/', $rel) as $seg) {
            if ($seg === '' || $seg[0] === '.') return true;
            foreach ($cfg['exclude_dirs'] as $e) {
                if ($e !== '' && strcasecmp($seg, $e) === 0) return true;
            }
        }
    }
    foreach ($cfg['exclude_dirs'] as $e) {
        if ($e !== '' && strcasecmp($entryName, $e) === 0) return true;
    }
    return false;
}

// === ENDPOINT: genera hash per password ===
if (isset($_GET['mkhash'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    // SECURITY: Use POST and require CSRF token to prevent password leakage via URL query strings/logs and CSRF attacks
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'method_not_allowed']); exit; }
    if ((int)$cfg['auth_mode'] === 0 || !auth_ok()) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit; }
    $payload = json_decode(file_get_contents('php://input'), true) ?: [];
    $csrf = (string)($payload['csrf'] ?? '');
    if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf)) {
        http_response_code(403); echo json_encode(['ok'=>false,'error'=>'forbidden']); exit;
    }
    $plain = (string)($payload['pass'] ?? '');
    if ($plain === '') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'empty']); exit; }
    $hash = password_hash($plain, PASSWORD_DEFAULT);
    echo json_encode(['ok'=>true,'hash'=>$hash], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

/* === ENDPOINT: login via AJAX (auth_mode >= 1) === */
if (isset($_GET['do_login'])) {
    header('Content-Type: application/json; charset=utf-8');
    if ((int)$cfg['auth_mode'] === 0) { http_response_code(404); echo json_encode(['ok'=>false]); exit; }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'method_not_allowed']); exit; }
    $payload = json_decode(file_get_contents('php://input'), true) ?: [];
    $pass = (string)($payload['pass'] ?? '');
    if ($pass === '') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'empty']); exit; }
    if (try_login($pass)) { echo json_encode(['ok'=>true]); exit; }
    http_response_code(401); echo json_encode(['ok'=>false,'error'=>'bad_password']); exit;
}
// === LOGIN GATE ===
if ((int)$cfg['auth_mode'] === 2 && !auth_ok()) {
    // Se è stato inviato il form, prova il login
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pass'])) {
        if (try_login($_POST['pass'])) {
            header('Location: ' . $self); exit;
        }
        $err = t('wrong_password');
    }
    ?><!doctype html>
    <html lang="<?php echo htmlspecialchars($LANG,ENT_QUOTES); ?>"><head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?php echo htmlspecialchars(t('login_required'),ENT_QUOTES); ?></title>
    <style>
    :root{--bg:#111;--fg:#eee;}
    body{margin:0;min-height:100vh;display:grid;place-items:center;background:var(--bg);color:var(--fg);font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;}
    form{background:#1b1b1b;padding:24px;border-radius:12px;box-shadow:0 6px 24px rgba(0,0,0,.35);min-width:260px}
    h1{font-size:18px;margin:0 0 12px 0}
    .row{display:flex;gap:8px}
    input[type=password]{flex:1;padding:10px 12px;border-radius:8px;border:1px solid #333;background:#0f0f0f;color:#fff}
    button{padding:10px 12px;border-radius:8px;border:1px solid #333;background:#222;color:#fff;cursor:pointer}
    .err{color:#ffb4b4;font-size:12px;margin-top:8px;height:14px}
    </style></head><body>
    <form method="post" autocomplete="current-password">
      <h1><?php echo htmlspecialchars($cfg['title'],ENT_QUOTES); ?></h1>
      <div class="row">
        <input name="pass" type="password" placeholder="<?php echo htmlspecialchars(t('password'),ENT_QUOTES); ?>" autofocus autocomplete="current-password" autocapitalize="none" autocorrect="off" spellcheck="false" inputmode="text">
        <button type="submit"><?php echo htmlspecialchars(t('enter'),ENT_QUOTES); ?></button>
      </div>
      <div class="err"><?php echo isset($err)?htmlspecialchars($err,ENT_QUOTES):''; ?></div>
    </form>
    </body></html><?php
    exit;
}

// Permetti richieste concorrenti mentre gira make_gif
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }

// === PREPARAZIONE CARTELLE ===
if (!is_dir($configDir)) { @mkdir($configDir, 0755, true); }
if (!is_dir($thumbsRoot)) { @mkdir($thumbsRoot, 0755, true); }
// Proteggi i file sensibili nella cartella di configurazione
$htaccessPath = $configDir . '/.htaccess';
if (!file_exists($htaccessPath)) {
    $ht = <<<HTA
# Blocca accesso diretto a file sensibili
<Files ".config.json">
  Require all denied
</Files>
<Files ".debug.log">
  Require all denied
</Files>
# Niente listing
Options -Indexes

# Cache aggressiva per risorse statiche generate (thumbs e gif)
<IfModule mod_headers.c>
  <FilesMatch "\\.(?:jpe?g|png|gif)$">
    Header set Cache-Control "public, max-age=31536000, immutable"
  </FilesMatch>
</IfModule>
HTA;
    @file_put_contents($htaccessPath, $ht);
}

/** Enforce sane defaults in the web root to avoid MultiViews negotiation issues */
$rootHtaccess = $baseDir . '/.htaccess';
$needLines = [];
$existing = is_file($rootHtaccess) ? @file_get_contents($rootHtaccess) : '';

if (stripos($existing, 'DirectoryIndex') === false || stripos($existing, 'index.php') === false) {
    $needLines[] = 'DirectoryIndex index.php index.html';
}

$hasMinusMulti = (stripos($existing, 'Options') !== false && stripos($existing, '-MultiViews') !== false);
if (!$hasMinusMulti) {
    $needLines[] = "<IfModule mod_negotiation.c>\n  Options -MultiViews\n</IfModule>";
}

if (!empty($needLines)) {
    $payload = rtrim($existing) . (strlen($existing) ? "\n\n" : '');
    $payload .= "# Added by Magic-Photo-Gallery to avoid content negotiation issues\n";
    $payload .= implode("\n", $needLines) . "\n";
    @file_put_contents($rootHtaccess, $payload);
}

// Flag estensione EXIF
$EXIF_AVAILABLE = extension_loaded('exif') || function_exists('exif_read_data');
function get_exif_orientation($path){
    if (!is_readable($path) || !function_exists('exif_read_data')) return 0;
    $exif = @exif_read_data($path, 'IFD0', true);
    if (!$exif) return 0;
    foreach ([
      $exif['IFD0']['Orientation'] ?? null,
      $exif['EXIF']['Orientation'] ?? null,
      $exif['Orientation'] ?? null
    ] as $o) {
      if (is_numeric($o)) { $o=(int)$o; if ($o>=1 && $o<=8) return $o; }
    }
    return 0;
}
if (!function_exists('imageflip')) {
    function imageflip(&$img,$mode){
        $w=imagesx($img); $h=imagesy($img); $d=imagecreatetruecolor($w,$h);
        if ($mode===IMG_FLIP_HORIZONTAL){ for($x=0;$x<$w;$x++) imagecopy($d,$img,$w-$x-1,0,$x,0,1,$h); }
        elseif($mode===IMG_FLIP_VERTICAL){ for($y=0;$y<$h;$y++) imagecopy($d,$img,0,$h-$y-1,0,$y,$w,1); }
        else { for($x=0;$x<$w;$x++) for($y=0;$y<$h;$y++) imagecopy($d,$img,$w-$x-1,$h-$y-1,$x,$y,1,1); }
        $img=$d; return true;
    }
}
// === FUNZIONE: genera thumbnail ===
function create_thumb($srcPath, $thumbPath, $thumbWidth, $force = false) {
    $GLOBALS['__LAST_THUMB_ERR'] = null;
    $ops = ['file'=>basename($srcPath),'lib'=>null,'exif'=>null,'actions'=>[]];
    // Assicurati che esista la cartella di destinazione
    $td = dirname($thumbPath);
    if (!is_dir($td)) { @mkdir($td, 0755, true); }

    // Se già esiste ed è più recente, non rigenerare
    if (!$force && file_exists($thumbPath) && filemtime($thumbPath) >= filemtime($srcPath)) {
        return true;
    }
    if ($force && file_exists($thumbPath)) {
        @unlink($thumbPath);
    }

    // Se file è HEIC/HEIF ma ImageMagick non supporta il formato, annota
    $ext = strtolower(pathinfo($srcPath, PATHINFO_EXTENSION));
    if (in_array($ext, ['heic','heif'])) {
        if (!class_exists('Imagick') || (empty(Imagick::queryFormats('HEIC')) && empty(Imagick::queryFormats('HEIF')))) {
            $GLOBALS['__LAST_THUMB_ERR'] = 'heic-unsupported: imagick senza delegate HEIC/HEIF';
            dbg_log(['thumb_error'=>$GLOBALS['__LAST_THUMB_ERR'], 'file'=>basename($srcPath)]);
        }
    }

    // Prova con Imagick se disponibile
    if (class_exists('Imagick')) {
        try {
            // Caricamento robusto per HEIC/HEIF: tenta immagine primaria e fallback [0]
            $img = new Imagick();
            $ext = strtolower(pathinfo($srcPath, PATHINFO_EXTENSION));
            if (in_array($ext,['heic','heif'])) {
                @$img->setOption('heic:primary','1');
                @$img->setOption('heic:ignore-auxiliary','true');
                try { $img->readImage($srcPath); $ops['actions'][]='read:primary'; }
                catch(Exception $e){ $img->clear(); $img=new Imagick(); $img->readImage($srcPath.'[0]'); $ops['actions'][]='read:primary'; }
            } else {
                $img->readImage($srcPath);
            }
            // Manually honor EXIF orientation before resizing
            $o = (function_exists('exif_read_data') ? get_exif_orientation($srcPath) : 0);
            $ops['exif'] = $o;
            if ($o >= 2) {
                $bg = new ImagickPixel('transparent');
                switch ($o) {
                    case 2: $img->flopImage();            $ops['actions'][] = 'flop'; break;     // mirror horizontal
                    case 3: $img->rotateImage($bg, 180);  $ops['actions'][] = 'rot180'; break;   // 180°
                    case 4: $img->flipImage();            $ops['actions'][] = 'flip'; break;     // mirror vertical
                    case 5: $img->transposeImage();       $ops['actions'][] = 'transpose'; break;// 90° CCW + mirror
                    case 6: $img->rotateImage($bg, 90);   $ops['actions'][] = 'rot90'; break;    // 90° CW
                    case 7: $img->transverseImage();      $ops['actions'][] = 'transverse'; break;// 90° CW + mirror
                    case 8: $img->rotateImage($bg, 270);  $ops['actions'][] = 'rot270'; break;   // 270° CW (90° CCW)
                }
            }
            $ops['lib'] = 'imagick';
            if (method_exists($img, 'autoOrientImage')) {
                $img->autoOrientImage();
                $ops['actions'][] = 'autoOrient';
            }
            // Assicura orientamento 'top-left' nel tag EXIF e rimuovi profili per evitare doppie rotazioni
            if (method_exists($img, 'setImageOrientation')) {
                $img->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
                $ops['actions'][] = 'setTopLeft';
            }
            // Calcola dimensioni DOPO l'auto-orientamento: lato lungo = $thumbWidth
            $w = $img->getImageWidth();
            $h = $img->getImageHeight();
            if ($w <= 0 || $h <= 0) { $img->clear(); return false; }
            $scale = $thumbWidth / max($w, $h);
            $newW = max(1, (int) round($w * $scale));
            $newH = max(1, (int) round($h * $scale));
            $ops['actions'][] = "resize:{$newW}x{$newH}";
            $img->resizeImage($newW, $newH, Imagick::FILTER_LANCZOS, 1, true);
            // Azzeriamo il tag di orientamento per evitare interpretazioni lato browser
            if (method_exists($img, 'setImageOrientation')) {
                $img->setImageOrientation(Imagick::ORIENTATION_UNDEFINED);
            }
            if (method_exists($img, 'stripImage')) {
                $img->stripImage();
            }
            // Forza formato di output a JPEG per compatibilità (HEIC/PNG in ingresso -> JPG in uscita)
            if (method_exists($img, 'setImageFormat')) {
                $img->setImageFormat('jpeg');
            }
            if (method_exists($img, 'setImageCompression')) {
                $img->setImageCompression(Imagick::COMPRESSION_JPEG);
            }
            if (method_exists($img, 'setImageCompressionQuality')) {
                $img->setImageCompressionQuality(85);
            }
            $img->writeImage($thumbPath);
            $img->clear();
            dbg_log(array_merge($ops, [
                'orig'=>img_dims($srcPath),
                'thumb'=>img_dims($thumbPath)
            ]));
            return true;
        } catch (Exception $e) {
            $GLOBALS['__LAST_THUMB_ERR'] = 'imagick-exception: ' . $e->getMessage();
            dbg_log(['thumb_error'=>$GLOBALS['__LAST_THUMB_ERR'], 'file'=>basename($srcPath)]);
            return false;
        }
    }

    // Altrimenti usa GD
    if (function_exists('imagecreatefromjpeg')) {
        // Prova JPEG poi PNG
        $src = @imagecreatefromjpeg($srcPath);
        if (!$src && function_exists('imagecreatefrompng')) {
            $src = @imagecreatefrompng($srcPath);
        }
        $ops['lib'] = 'gd';
        global $EXIF_AVAILABLE;
        // Auto-orienta in base all'EXIF (se presente)
        if ($EXIF_AVAILABLE) {
            $o = get_exif_orientation($srcPath);
            $ops['exif'] = $o;
            if ($o >= 2) {
                switch ($o) {
                    case 2:
                        imageflip($src, IMG_FLIP_HORIZONTAL);
                        $ops['actions'][] = 'flop';
                        break;
                    case 3:
                        $src = imagerotate($src, 180, 0);
                        $ops['actions'][] = 'rot180';
                        break;
                    case 4:
                        imageflip($src, IMG_FLIP_VERTICAL);
                        $ops['actions'][] = 'flip';
                        break;
                    case 5:
                        imageflip($src, IMG_FLIP_HORIZONTAL);
                        $src = imagerotate($src, -90, 0);
                        $ops['actions'][] = 'transpose';
                        break;
                    case 6:
                        $src = imagerotate($src, -90, 0);
                        $ops['actions'][] = 'rot90';
                        break;
                    case 7:
                        imageflip($src, IMG_FLIP_HORIZONTAL);
                        $src = imagerotate($src, 90, 0);
                        $ops['actions'][] = 'transverse';
                        break;
                    case 8:
                        $src = imagerotate($src, 90, 0);
                        $ops['actions'][] = 'rot270';
                        break;
                }
            }
        }
        if (!$src) {
            $GLOBALS['__LAST_THUMB_ERR'] = 'gd-open-failed';
            dbg_log(['thumb_error'=>$GLOBALS['__LAST_THUMB_ERR'], 'file'=>basename($srcPath)]);
            return false;
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $scale = $thumbWidth / max($w, $h); // lato lungo = $thumbWidth
        $newW = max(1, (int) round($w * $scale));
        $newH = max(1, (int) round($h * $scale));
        $ops['actions'][] = "resize:{$newW}x{$newH}";
        $thumb = imagecreatetruecolor($newW, $newH);
        imagecopyresampled($thumb, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
        imagejpeg($thumb, $thumbPath, 85);
        // Nota: EXIF non viene preservato in GD, quindi non c'è rischio di doppia rotazione.
        imagedestroy($src);
        imagedestroy($thumb);
        dbg_log(array_merge($ops, [
            'orig'=>img_dims($srcPath),
            'thumb'=>img_dims($thumbPath)
        ]));
        return true;
    }

    return false;
}

// === FUNZIONE: GIF animata di cartella (Imagick) ===
function create_folder_gif($srcDirAbs, $gifPath, $frameSize, $maxFrames = 12, $delayCs = 8, $progressPath = null, $mode = 'transition', $fadeFrames = 6) {
  $mode = $mode ?: 'transition';
  $fadeFrames = max(0, (int)$fadeFrames);
  // Raccogli immagini (solo primo livello della cartella), case-insensitive
    $files = list_images($srcDirAbs);
    if (empty($files)) return false;

    $total = min($maxFrames, count($files));
    if ($progressPath) {
        $td = dirname($progressPath);
        if (!is_dir($td)) @mkdir($td, 0755, true);
        $tmp = $progressPath . '.tmp';
        @file_put_contents($tmp, json_encode([
            'total' => $total,
            'done'  => 0,
            'started_at' => time()
        ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), LOCK_EX);
        @rename($tmp, $progressPath);
    }

    // Se non c'è Imagick, usa un PNG/JPEG statico del primo file convertito a GIF
    if (!class_exists('Imagick')) {
        if ($mode === 'transition') {
            $first = $files[0];
            // prepara thumb singola e rinominala .gif
            $tmpJpg = $gifPath . '.tmp.jpg';
            $td = dirname($gifPath);
            if (!is_dir($td)) @mkdir($td, 0755, true);
            if (!create_thumb($first, $tmpJpg, $frameSize, true)) return false;
            // copia come gif "finta"
            $ok = @copy($tmpJpg, $gifPath);
            if ($progressPath) {
                $tmp = $progressPath . '.tmp';
                @file_put_contents($tmp, json_encode([
                    'total' => 1,
                    'done'  => 1,
                    'done_at' => time()
                ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), LOCK_EX);
                @rename($tmp, $progressPath);
            }
            return $ok;
        } else {
            // cut mode: fallback identico (statico)
            $first = $files[0];
            $tmpJpg = $gifPath . '.tmp.jpg';
            $td = dirname($gifPath);
            if (!is_dir($td)) @mkdir($td, 0755, true);
            if (!create_thumb($first, $tmpJpg, $frameSize, true)) return false;
            $ok = @copy($tmpJpg, $gifPath);
            if ($progressPath) {
                $tmp = $progressPath . '.tmp';
                @file_put_contents($tmp, json_encode([
                    'total' => 1,
                    'done'  => 1,
                    'done_at' => time()
                ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), LOCK_EX);
                @rename($tmp, $progressPath);
            }
            return $ok;
        }
    }

    $td = dirname($gifPath);
    if (!is_dir($td)) @mkdir($td, 0755, true);
    $base = new Imagick();
    $base->setFormat('gif');
    $count = 0;
    foreach ($files as $f) {
        if ($count >= $maxFrames) break;
        try {
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            if (in_array($ext,['heic','heif'])) {
                $frame = new Imagick();
                @$frame->setOption('heic:primary','1');
                try { $frame->readImage($f); }
                catch(Exception $e){ $frame->clear(); $frame=new Imagick(); $frame->readImage($f.'[0]'); }
            } else {
                $frame = new Imagick($f);
            }
            if (method_exists($frame,'autoOrientImage')) $frame->autoOrientImage();
            $w = $frame->getImageWidth(); $h = $frame->getImageHeight();
            $scale = $frameSize / max($w,$h);
            $newW = max(1,(int)round($w*$scale)); $newH = max(1,(int)round($h*$scale));
            $frame->resizeImage($newW, $newH, Imagick::FILTER_LANCZOS, 1, true);
            if (method_exists($frame,'stripImage')) $frame->stripImage();
            $frame->setImagePage(0,0,0,0);
            $frame->setImageDelay($delayCs);
            $base->addImage($frame);
            $base->setImageDispose(Imagick::DISPOSE_BACKGROUND);
            $count++;
            if ($progressPath) {
                $tmp = $progressPath . '.tmp';
                @file_put_contents($tmp, json_encode([
                    'total' => min($maxFrames, count($files)),
                    'done'  => $count
                ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), LOCK_EX);
                @rename($tmp, $progressPath);
            }
        } catch (Exception $e) { continue; }
    }
    if ($count === 0) { return false; }
    $base = $base->coalesceImages();
    if ($mode === 'transition' && $fadeFrames > 0) {
        // Genera dissolvenze tra i frame
        $morphed = $base->morphImages($fadeFrames);
        // Imposta un delay più breve per i frame intermedi
        foreach ($morphed as $i => $frm) { $frm->setImageDelay(max(1, (int)floor($delayCs / 2))); }
        $ok = $morphed->writeImages($gifPath, true);
        $morphed->clear();
    } else {
        // Taglio secco
        $ok = $base->writeImages($gifPath, true);
    }
    $base->clear();
    if ($progressPath) {
        $tmp = $progressPath . '.tmp';
        @file_put_contents($tmp, json_encode([
            'total' => $total,
            'done'  => $count,
            'done_at' => time()
        ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), LOCK_EX);
        @rename($tmp, $progressPath);
    }
    return $ok;
}

// === ENDPOINT: stato avanzamento GIF cartella ===
if (isset($_GET['gif_progress'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    // SECURITY: Prevent unauthorized information leakage when gallery requires full authentication
    if ((int)$cfg['auth_mode'] === 2 && !auth_ok()) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit; }
    $dirRel = trim(str_replace(['\\'],'/', ltrim($_GET['gif_progress'],'/')));
    if (strpos($dirRel,'..') !== false) { echo json_encode(['ok'=>false]); exit; }
    // SECURITY: Reject excluded or hidden directories to avoid probing internal folders
    if ($dirRel !== '' && is_excluded_dir(basename($dirRel), $dirRel)) { echo json_encode(['ok'=>false,'error'=>'excluded']); exit; }
    $ppath = folder_gif_progress_path($dirRel, $thumbsRoot, $prefThumb);
    if (!is_file($ppath)) { echo json_encode(['ok'=>true,'exists'=>false,'total'=>0,'done'=>0]); exit; }
    $data = @json_decode(@file_get_contents($ppath), true) ?: [];
    $total = isset($data['total']) ? (int)$data['total'] : 0;
    $done  = isset($data['done'])  ? (int)$data['done']  : 0;
    echo json_encode(['ok'=>true,'exists'=>true,'total'=>$total,'done'=>$done], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

// === ENDPOINT ASINCRONO: genera GIF anteprima cartella ===
if (isset($_GET['make_gif'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    // SECURITY: Prevent unauthorized resource exhaustion/DoS and GIF generation when auth_mode == 2
    if ((int)$cfg['auth_mode'] === 2 && !auth_ok()) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit; }
    @set_time_limit(0);
    $dirRel = trim(str_replace(['\\'],'/', ltrim($_GET['make_gif'],'/')));
    if (strpos($dirRel,'..') !== false) { echo json_encode(['ok'=>false]); exit; }
    $srcAbs = realpath($baseDir . ($dirRel ? '/' . $dirRel : '')) ?: $baseDir;
    // SECURITY: Enforce directory boundary with trailing separator to prevent prefix confusion
    if ($srcAbs !== $realBaseDir && strpos($srcAbs, $realBaseDir . DIRECTORY_SEPARATOR) !== 0) { echo json_encode(['ok'=>false]); exit; }
    // rifiuta se il target è escluso
    if ($dirRel !== '') {
        $last = basename($dirRel);
        if (is_excluded_dir($last, $dirRel)) { echo json_encode(['ok'=>false,'error'=>'excluded']); exit; }
    }
    $gifAbs = ($dirRel === '' ? ($thumbsRoot . '/' . $folderGifName) : ($thumbsRoot . '/' . implode('/', array_map(fn($seg) => $prefFolder.$seg, explode('/', $dirRel))) . '/' . $folderGifName));
    $progressPath = folder_gif_progress_path($dirRel, $thumbsRoot, $prefThumb);
    $ok = create_folder_gif(
        $srcAbs,
        $gifAbs,
        max(96, (int)$cfg['thumb_view']),
        (int)$cfg['folder_gif_max_frames'],
        (int)$cfg['folder_gif_delay_cs'],
        $progressPath,
        (string)$cfg['folder_gif_mode'],
        (int)$cfg['folder_gif_fade_frames']
    );
    // aggiorna .index.json per questa cartella
    if ($ok) { idx_update_gif($dirRel, $gifAbs); }
    echo json_encode([
        'ok' => (bool)$ok,
        'gif' => $ok ? rel_url($gifAbs) . '?v=' . time() : null
    ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

// === ENDPOINT: EXIF per una immagine (con fallback HEIC/XMP via Imagick) ===
if (isset($_GET['exif'])) {
    header('Content-Type: application/json; charset=utf-8');
    // SECURITY: Prevent unauthorized access to photo metadata when auth_mode == 2
    if ((int)$cfg['auth_mode'] === 2 && !auth_ok()) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit; }
    $name = basename((string)$_GET['exif']);
    // SECURITY: Reject hidden/dotfiles and non-image extensions to prevent metadata probing or processing of arbitrary server files
    if ($name === '' || $name[0] === '.' || !is_image_ext($name)) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'invalid_image']); exit; }
    $srcPath = $currentAbs . '/' . $name;
    if (!is_file($srcPath)) { echo json_encode(['ok'=>false,'error'=>'not_found']); exit; }

    $out = [ 'ok'=>true, 'name'=>$name, 'has_exif'=>false, 'fields'=>[] ];

    // Dimensioni/MIME (sempre)
    $dims = @getimagesize($srcPath);
    if ($dims) {
        $out['fields'][t('dims')] = ($dims[0] ?? 0) . ' × ' . ($dims[1] ?? 0);
        if (!empty($dims['mime'])) $out['fields']['MIME'] = $dims['mime'];
    }

    // Helper per formattare numeri frazionari tipo "35/1"
    $fmtFrac = function($v){
        if (is_string($v) && strpos($v,'/') !== false) {
            list($a,$b) = array_map('floatval', explode('/', $v, 2));
            if ($b) $v = $a / $b;
        }
        if (is_numeric($v)) return (float)$v;
        return $v;
    };

    // --- 1) Prova EXIF nativo di PHP (funziona bene su JPEG/TIFF; non su HEIC) ---
    $exifOK = false;
    if ($EXIF_AVAILABLE) {
        $ex = @exif_read_data($srcPath, null, true, false);
        if (is_array($ex)) {
            $exifOK = true;
            $get = function($grp,$key) use ($ex){ return $ex[$grp][$key] ?? null; };

            $cam = trim(($get('IFD0','Make') ?? '') . ' ' . ($get('IFD0','Model') ?? ''));
            if ($cam !== '') $out['fields'][t('camera')] = $cam;

            $lens = $get('EXIF','UndefinedTag:0xA432') ?? $get('EXIF','LensModel') ?? $get('COMPUTED','LensModel') ?? null;
            if ($lens) $out['fields'][t('lens')] = is_array($lens)? implode(' ', $lens) : $lens;

            $dt = $get('EXIF','DateTimeOriginal') ?? $get('EXIF','CreateDate') ?? $get('IFD0','DateTime') ?? null;
            if ($dt) $out['fields'][t('date')] = preg_replace('/:/','-',substr($dt,0,10),1) . ' ' . substr($dt,11);

            $iso = $get('EXIF','PhotographicSensitivity') ?? $get('EXIF','ISOSpeedRatings') ?? null;
            if ($iso) $out['fields']['ISO'] = is_array($iso)? implode(',', $iso) : $iso;

            $fl = $get('EXIF','FocalLengthIn35mmFilm') ?? $get('EXIF','FocalLength') ?? null;
            if ($fl) {
                if (is_string($fl) && strpos($fl,'/')!==false) { list($a,$b)=array_map('floatval', explode('/', $fl, 2)); $fl = $b? ($a/$b) : $a; }
                $out['fields'][t('focal')] = rtrim(rtrim(sprintf('%.1f', (float)$fl), '0'), '.') . ' mm';
            }

            $ap = $get('EXIF','FNumber') ?? $get('EXIF','ApertureValue') ?? null;
            if ($ap) {
                if (is_string($ap) && strpos($ap,'/')!==false) { list($a,$b)=array_map('floatval', explode('/', $ap, 2)); $ap = $b? ($a/$b) : $a; }
                $out['fields'][t('aperture')] = 'f/' . rtrim(rtrim(sprintf('%.1f', (float)$ap), '0'), '.');
            }

            $exp = $get('EXIF','ExposureTime') ?? null;
            if ($exp) {
                if (is_string($exp) && strpos($exp,'/')!==false) { $out['fields'][t('exptime')] = $exp . ' s'; }
                else { $val = (float)$exp; $out['fields'][t('exptime')] = $val >= 1 ? sprintf('%.1f s', $val) : ('1/' . max(1, round(1/$val)) . ' s'); }
            }

            if (!empty($ex['GPS'])) $out['fields']['GPS'] = t('present');
        }
    }

    // --- 2) Fallback HEIC/HEIF: estrai profilo EXIF/XMP via Imagick e parser nativo ---
    $ext = strtolower(pathinfo($srcPath, PATHINFO_EXTENSION));
    if (!$exifOK && class_exists('Imagick') && in_array($ext, ['heic','heif','heics','heifs'], true)) {
        try {
            $im = new Imagick();
            // Apri l'immagine primaria ed ignora gli ausiliari (evita errori "Too many auxiliary...")
            @$im->setOption('heic:primary','1');
            @$im->setOption('heic:ignore-auxiliary','true');
            try { $im->readImage($srcPath); }
            catch (Exception $e) { $im->clear(); $im = new Imagick(); $im->readImage($srcPath.'[0]'); }

            // 2.a) Prova ad estrarre il profilo EXIF binario
            $exifBlob = $im->getImageProfile('exif');
            if (!empty($exifBlob)) {
                // Costruisci un JPEG sintetico che contiene solo il segmento APP1 EXIF
                $payload = "Exif\0\0" . $exifBlob;
                $jpeg = "\xFF\xD8" . "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload . "\xFF\xD9";
                $tmpPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'mpg_exif_' . bin2hex(random_bytes(6)) . '.jpg';
                @file_put_contents($tmpPath, $jpeg);
                $ex = @exif_read_data($tmpPath, null, true, false);
                @unlink($tmpPath);

                if (is_array($ex) && !empty($ex)) {
                    $exifOK = true;
                    $get = function($grp,$key) use ($ex){ return $ex[$grp][$key] ?? null; };

                    $cam = trim(($get('IFD0','Make') ?? '') . ' ' . ($get('IFD0','Model') ?? ''));
                    if ($cam !== '') $out['fields'][t('camera')] = $cam;

                    $lens = $get('EXIF','UndefinedTag:0xA432') ?? $get('EXIF','LensModel') ?? $get('COMPUTED','LensModel') ?? null;
                    if ($lens) $out['fields'][t('lens')] = is_array($lens)? implode(' ', $lens) : $lens;

                    $dt = $get('EXIF','DateTimeOriginal') ?? $get('EXIF','CreateDate') ?? $get('IFD0','DateTime') ?? null;
                    if ($dt) $out['fields'][t('date')] = preg_replace('/:/','-',substr($dt,0,10),1) . ' ' . substr($dt,11);

                    $iso = $get('EXIF','PhotographicSensitivity') ?? $get('EXIF','ISOSpeedRatings') ?? null;
                    if ($iso) $out['fields']['ISO'] = is_array($iso)? implode(',', $iso) : $iso;

                    $fl = $get('EXIF','FocalLengthIn35mmFilm') ?? $get('EXIF','FocalLength') ?? null;
                    if ($fl) {
                        if (is_string($fl) && strpos($fl,'/')!==false) { list($a,$b)=array_map('floatval', explode('/', $fl, 2)); $fl = $b? ($a/$b) : $a; }
                        $out['fields'][t('focal')] = rtrim(rtrim(sprintf('%.1f', (float)$fl), '0'), '.') . ' mm';
                    }

                    $ap = $get('EXIF','FNumber') ?? $get('EXIF','ApertureValue') ?? null;
                    if ($ap) {
                        if (is_string($ap) && strpos($ap,'/')!==false) { list($a,$b)=array_map('floatval', explode('/', $ap, 2)); $ap = $b? ($a/$b) : $a; }
                        $out['fields'][t('aperture')] = 'f/' . rtrim(rtrim(sprintf('%.1f', (float)$ap), '0'), '.');
                    }

                    $exp = $get('EXIF','ExposureTime') ?? null;
                    if ($exp) {
                        if (is_string($exp) && strpos($exp,'/')!==false) { $out['fields'][t('exptime')] = $exp . ' s'; }
                        else { $val = (float)$exp; $out['fields'][t('exptime')] = $val >= 1 ? sprintf('%.1f s', $val) : ('1/' . max(1, round(1/$val)) . ' s'); }
                    }

                    if (!empty($ex['GPS'])) $out['fields']['GPS'] = t('present');
                }
            }

            // 2.b) Integra con XMP se disponibile o se EXIF non ha dato nulla (alcuni HEIC Apple usano soprattutto XMP)
            $props = [];
            foreach (['xmp:*','exif:*','iptc:*'] as $pat) {
                foreach ($im->getImageProperties($pat) as $k => $v) { $props[$k] = $v; }
            }
            $getP = function(array $keys) use ($props) {
                foreach ($keys as $k) { if (isset($props[$k])) return $props[$k]; }
                return null;
            };
            // Solo se i campi non sono già stati valorizzati
            if (empty($out['fields'][t('camera')])) {
                $camMake  = $getP(['xmp:tiff:Make','xmp:Make','exif:Make']);
                $camModel = $getP(['xmp:tiff:Model','xmp:Model','exif:Model']);
                $cam = trim(($camMake?:'') . ' ' . ($camModel?:''));
                if ($cam !== '') $out['fields'][t('camera')] = $cam;
            }
            if (empty($out['fields'][t('lens')])) {
                $lens = $getP(['xmp:aux:Lens','xmp:Lens','xmp:exif:LensModel','exif:LensModel']);
                if ($lens) $out['fields'][t('lens')] = $lens;
            }
            if (empty($out['fields'][t('date')])) {
                $dt = $getP(['xmp:photoshop:DateCreated','xmp:CreateDate','xmp:DateTimeOriginal','exif:DateTimeOriginal','exif:CreateDate']);
                if ($dt) {
                    if (preg_match('/^\d{4}:\d{2}:\d{2} /', $dt)) {
                        $out['fields'][t('date')] = preg_replace('/:/','-',substr($dt,0,10),1) . ' ' . substr($dt,11);
                    } else { $out['fields'][t('date')] = $dt; }
                }
            }
            if (empty($out['fields']['ISO'])) {
                $iso = $getP(['xmp:exif:ISOSpeedRatings','exif:ISOSpeedRatings','exif:PhotographicSensitivity']);
                if ($iso) $out['fields']['ISO'] = is_array($iso)? implode(',', $iso) : $iso;
            }
            if (empty($out['fields'][t('focal')])) {
                $fl = $getP(['xmp:exif:FocalLength','exif:FocalLength']);
                if ($fl) {
                    if (is_string($fl) && strpos($fl,'/')!==false) { list($a,$b)=array_map('floatval', explode('/', $fl, 2)); $fl = $b? ($a/$b) : $a; }
                    $out['fields'][t('focal')] = rtrim(rtrim(sprintf('%.1f', (float)$fl), '0'), '.') . ' mm';
                }
            }
            if (empty($out['fields'][t('aperture')])) {
                $ap = $getP(['xmp:exif:FNumber','exif:FNumber','exif:ApertureValue']);
                if ($ap) {
                    if (is_string($ap) && strpos($ap,'/')!==false) { list($a,$b)=array_map('floatval', explode('/', $ap, 2)); $ap = $b? ($a/$b) : $a; }
                    $out['fields'][t('aperture')] = 'f/' . rtrim(rtrim(sprintf('%.1f', (float)$ap), '0'), '.');
                }
            }
            if (empty($out['fields'][t('exptime')])) {
                $exp = $getP(['xmp:exif:ExposureTime','exif:ExposureTime']);
                if ($exp) {
                    if (is_string($exp) && strpos($exp,'/')!==false) { $out['fields'][t('exptime')] = $exp . ' s'; }
                    else { $val = (float)$exp; $out['fields'][t('exptime')] = $val >= 1 ? sprintf('%.1f s', $val) : ('1/' . max(1, round(1/$val)) . ' s'); }
                }
            }
            if (empty($out['fields']['GPS']) && ($getP(['exif:GPSLatitude','xmp:exif:GPSLatitude']))) {
                $out['fields']['GPS'] = t('present');
            }

            if (!empty($out['fields'])) $out['has_exif'] = true;
            $im->clear();
        } catch (Exception $e) {
            // mantieni silenzioso in caso di delegate mancante
        }
    } else {
        if (!empty($out['fields'])) $out['has_exif'] = true;
    }

    echo json_encode($out, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

// === ENDPOINT DIAGNOSTICA: info su un file
if (isset($_GET['diag'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (!auth_ok()) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit; }
    $name = basename($_GET['diag']);
    // SECURITY: Reject hidden/dotfiles and non-image files to avoid probing server files
    if ($name === '' || $name[0] === '.' || !is_image_ext($name)) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'invalid_image']); exit; }
    $srcPath = ($currentAbs . '/' . $name);
    $thumbPath = ($thumbDirForView . '/' . thumb_name($name));
    $out = [
        'file' => $name,
        'exists_src' => is_file($srcPath),
        'exists_thumb' => is_file($thumbPath),
        'exif_available' => (bool)$EXIF_AVAILABLE,
        'exif_orientation' => $EXIF_AVAILABLE ? get_exif_orientation($srcPath) : null,
        'imagick_available' => class_exists('Imagick'),
        'gd_available' => function_exists('imagecreatefromjpeg'),
        'src_dims' => img_dims($srcPath),
        'thumb_dims' => img_dims($thumbPath),
        'thumb_mtime' => is_file($thumbPath) ? filemtime($thumbPath) : null,
        'will_use' => (class_exists('Imagick') ? 'imagick' : (function_exists('imagecreatefromjpeg') ? 'gd' : 'none')),
    ];
    echo json_encode($out, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

// === ENDPOINT ASINCRONO: genera una singola thumbnail e ritorna JSON ===
if (isset($_GET['make_thumb'])) {
    header('Content-Type: application/json; charset=utf-8');
    // SECURITY: Prevent unauthorized thumbnail creation and file access when auth_mode == 2
    if ((int)$cfg['auth_mode'] === 2 && !auth_ok()) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit; }
    @set_time_limit(0);
    $force = isset($_GET['force']) && $_GET['force'] === '1';

    $name = basename($_GET['make_thumb']); // sanitizza
    // SECURITY: Reject hidden/dotfiles and non-image extensions to prevent processing arbitrary files or DoS
    if ($name === '' || $name[0] === '.' || !is_image_ext($name)) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'invalid_image']); exit; }
    $srcPath = $currentAbs . '/' . $name;
    $thumbPath = $thumbDirForView . '/' . thumb_name($name);

if (!is_dir($thumbDirForView)) { @mkdir($thumbDirForView, 0755, true); }

    $ok = is_file($srcPath) ? create_thumb($srcPath, $thumbPath, $thumbWidth, $force) : false;

    // aggiorna .index.json per questa cartella
    if ($ok && is_file($thumbPath)) { idx_update_thumb($relDir, $srcPath, $thumbPath); }

    echo json_encode([
        'ok' => (bool)$ok,
        'name' => $name,
        'thumb' => $ok && file_exists($thumbPath) ? rel($thumbPath) : null,
        'mtime' => $ok && file_exists($thumbPath) ? filemtime($thumbPath) : null,
        'exif' => (bool)$EXIF_AVAILABLE,
        'error' => $ok ? null : ($GLOBALS['__LAST_THUMB_ERR'] ?? 'gen-failed')
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// === ENDPOINT: imposta lingua (salvata in .config.json) ===
if (isset($_GET['set_lang'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'method_not_allowed']); exit; }
    $payload = json_decode(file_get_contents('php://input'), true) ?: [];
    if ((int)$cfg['auth_mode'] >= 1) {
        if (!auth_ok()) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit; }
        $csrf = (string)($payload['csrf'] ?? '');
        if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf)) {
            http_response_code(403); echo json_encode(['ok'=>false,'error'=>'forbidden']); exit;
        }
    }
    $lang = (string)($payload['lang'] ?? '');
    if ($lang !== 'auto' && !isset(mpg_i18n_data()[$lang])) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'bad_lang']); exit; }
    $cur = is_file($configFile) ? json_decode((string)@file_get_contents($configFile), true) : [];
    if (!is_array($cur)) $cur = [];
    $cur['language'] = $lang;
    $saved = @file_put_contents($configFile, json_encode($cur, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), LOCK_EX);
    if ($saved === false) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'write_failed']); exit; }
    echo json_encode(['ok'=>true,'language'=>$lang]);
    exit;
}

// === ENDPOINT: purge cache thumbs+gif per una cartella ===
if (isset($_GET['purge_cache'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    // Solo POST
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'method_not_allowed']); exit; }

    // Se auth_mode >=1 richiede login e CSRF valido; se 0 non richiede auth
    if ((int)$cfg['auth_mode'] >= 1) {
        if (!auth_ok()) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit; }
        $payload = json_decode(file_get_contents('php://input'), true) ?: [];
        $csrf = (string)($payload['csrf'] ?? '');
        if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf)) {
            http_response_code(403); echo json_encode(['ok'=>false,'error'=>'forbidden']); exit;
        }
    } else {
        $payload = json_decode(file_get_contents('php://input'), true) ?: [];
    }

    $targetRel = trim(str_replace(['\\'],'/', ltrim($_GET['dir'] ?? $relDir, '/')));
    if (strpos($targetRel,'..') !== false) { echo json_encode(['ok'=>false,'error'=>'bad_rel']); exit; }
    // SECURITY: Reject excluded or hidden directories from being purged
    if ($targetRel !== '' && is_excluded_dir(basename($targetRel), $targetRel)) { echo json_encode(['ok'=>false,'error'=>'excluded']); exit; }
    $recursive = !empty($payload['recursive']);
    $purged = ['thumbs'=>0,'gifs'=>0,'dirs'=>0];

    $purgeOne = function($rel) use ($thumbsRoot, $prefThumb, $folderGifName, &$purged, $prefFolder) {
        $tDir = ($rel === '' ? $thumbsRoot : ($thumbsRoot . '/' . implode('/', array_map(fn($seg) => $prefFolder.$seg, explode('/', $rel)))));
        if (!is_dir($tDir)) return;
        if ($h=@opendir($tDir)) {
            while(false!==($f=readdir($h))){
                if ($f==='.'||$f==='..') continue;
                $p=$tDir.'/'.$f; if (is_dir($p)) continue;
                if ($f===$folderGifName){ @unlink($p); $purged['gifs']++; }
                if (strpos($f,$prefThumb)===0){ @unlink($p); $purged['thumbs']++; }
            }
            closedir($h);
        }
        $purged['dirs']++;
    };

    if ($recursive) {
        $start = ($targetRel === '' ? $thumbsRoot : ($thumbsRoot . '/' . implode('/', array_map(fn($seg) => $prefFolder.$seg, explode('/', $targetRel)))));
        $len = strlen($thumbsRoot) + 1;
        if (is_dir($start)) {
            $dirs = [$start];
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($start, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($it as $path=>$info) if ($info->isDir()) $dirs[]=$path;
            $rels = array_unique(array_map(function($d)use($len){
                $d=str_replace('\\','/',$d); return ltrim(substr($d,$len),'/');
            },$dirs));
            foreach($rels as $r) $purgeOne($r);
        }
    } else {
        $purgeOne($targetRel);
    }
    // aggiorna o rimuove .index.json se la cartella cache è vuota
    $touchIndex = function($rel) use ($thumbsRoot, $prefFolder){
        $p = ($rel===''?$thumbsRoot:$thumbsRoot.'/'.implode('/',array_map(fn($seg)=>$prefFolder.$seg,explode('/',$rel)))).'/.index.json';
        if (!is_file($p)) return;
        $dir = dirname($p);
        $has=false;
        if ($h=@opendir($dir)) {
            while(false!==($f=readdir($h))){
                if($f==='.'||$f==='..') continue;
                if (is_file($dir.'/'.$f) && $f!=='.index.json') { $has=true; break; }
            }
            closedir($h);
        }
        if ($has) {
            $j = json_decode(@file_get_contents($p), true) ?: [];
            $j['generated_at'] = time();
            write_atomic($p, json_encode($j, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        } else {
            @unlink($p);
        }
    };
    $touchIndex($targetRel);
    echo json_encode(['ok'=>true,'purged'=>$purged], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Helper: rimozione ricorsiva directory
function rrmdir($dir){
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $p=>$i){ $i->isDir() ? @rmdir($p) : @unlink($p); }
    @rmdir($dir);
}
// --- Helper: elenco sottocartelle immediate non escluse
function list_subdirs($dirAbs, $relBase){
    $out=[]; $scan=@scandir($dirAbs)?:[];
    foreach($scan as $e){
        if($e==='.'||$e==='..'||$e[0]==='.') continue;
        $abs=$dirAbs.'/'.$e; if(!is_dir($abs)) continue;
        $rel=ltrim(($relBase?$relBase.'/':'').$e,'/');
        if(is_excluded_dir($e,$rel)) continue;
        $out[]=['name'=>$e,'abs'=>$abs,'rel'=>$rel];
    }
    return $out;
}
// --- Helper: mappa baseName -> sorgente
function build_src_map($dirAbs){
    $m=[]; foreach(list_images($dirAbs) as $p){
        $m[strtolower(pathinfo($p,PATHINFO_FILENAME))]=$p;
    } return $m;
}
// --- Sincronizza una cartella; crea indice .index.json
function sync_one_dir($rel,$baseDir,$thumbsRoot,$prefThumb,$folderGifName,$cfg){
    global $prefFolder;
    $st=['removed_thumbs'=>0,'removed_gifs'=>0,'removed_dirs'=>0,'regen_thumbs'=>0,'regen_gifs'=>0,'created_thumbs'=>0,'indexed'=>0];
    $srcDir=($rel===''?$baseDir:$baseDir.'/'.$rel);
    $thDir =($rel===''?$thumbsRoot:$thumbsRoot.'/'.implode('/',array_map(fn($seg)=>$prefFolder.$seg,explode('/',$rel))));
    if(!is_dir($srcDir)){ if(is_dir($thDir)){ rrmdir($thDir); $st['removed_dirs']++; } return $st; }
    if(!is_dir($thDir)) @mkdir($thDir,0755,true);

    // Performance optimization (Bolt ⚡): Call list_images($srcDir) once and build $srcMap from it.
    // Avoids redundant filesystem traversal and natsort overhead from duplicate list_images() calls.
    $files=list_images($srcDir);
    $srcMap=[];
    foreach($files as $p){
        $srcMap[strtolower(pathinfo($p,PATHINFO_FILENAME))]=$p;
    }

    // rimuovi thumbs orfane
    if($h=@opendir($thDir)){ while(false!==($f=readdir($h))){
        if($f==='.'||$f==='..') continue;
        $p=$thDir.'/'.$f; if(is_dir($p)) continue;
        if($f===$folderGifName) continue;
        if(strpos($f,$prefThumb)!==0) continue;
        $base=strtolower(preg_replace('/^'.preg_quote($prefThumb,'/').'/','',preg_replace('/\.jpg$/i','',$f)));
        $srcP=$srcMap[$base]??null;
        if(!$srcP||!is_file($srcP)){ @unlink($p); $st['removed_thumbs']++; }
    } closedir($h); }

    // crea/rigenera thumbs
    foreach($srcMap as $base=>$srcP){
        $thumbPath=$thDir.'/'.$prefThumb.pathinfo($srcP,PATHINFO_FILENAME).'.jpg';
        $sm=@filemtime($srcP)?:0; $tm=@filemtime($thumbPath)?:0;
        if(!is_file($thumbPath)){
            if(create_thumb($srcP,$thumbPath,(int)$cfg['thumb_make'],true)) $st['created_thumbs']++;
        } elseif($tm<$sm){
            if(create_thumb($srcP,$thumbPath,(int)$cfg['thumb_make'],true)) $st['regen_thumbs']++;
        }
    }

    // gif cartella
    $gifPath=$thDir.'/'.$folderGifName;
    if(empty($files)){ if(is_file($gifPath)){ @unlink($gifPath); $st['removed_gifs']++; } }
    else {
        $latest=0; foreach($files as $sp){ $t=@filemtime($sp)?:0; if($t>$latest)$latest=$t; }
        $gm=@filemtime($gifPath)?:0;
        if($gm<$latest){
            $ok=create_folder_gif($srcDir,$gifPath,max(96,(int)$cfg['thumb_view']),(int)$cfg['folder_gif_max_frames'],(int)$cfg['folder_gif_delay_cs'],null,(string)$cfg['folder_gif_mode'],(int)$cfg['folder_gif_fade_frames']);
            if($ok && $gm) $st['regen_gifs']++;
        }
    }

    // indice
    $idx=[
        'dir_rel'=>$rel,
        'generated_at'=>time(),
        'thumbs'=>[],
        'gif'=>['exists'=>is_file($gifPath),'path'=>is_file($gifPath)?$gifPath:null,'mtime'=>is_file($gifPath)?filemtime($gifPath):null]
    ];
    if($h=@opendir($thDir)){ while(false!==($f=readdir($h))){
        if($f==='.'||$f==='..') continue;
        $p=$thDir.'/'.$f; if(is_dir($p)) continue;
        if(strpos($f,$prefThumb)!==0) continue;
        $base=strtolower(preg_replace('/^'.preg_quote($prefThumb,'/').'/','',preg_replace('/\.jpg$/i','',$f)));
        $srcP=$srcMap[$base]??null;
        $idx['thumbs'][$f]=[
            'src_rel'=>$srcP?ltrim(str_replace(str_replace('\\','/',$baseDir).'/','',str_replace('\\','/',$srcP)),'/'):null,
            'src_mtime'=>$srcP?@filemtime($srcP):null,
            'thumb_mtime'=>@filemtime($p)
        ];
    } closedir($h); }
    write_atomic($thDir.'/.index.json', json_encode($idx, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    if(is_file($thDir.'/.index.json')) $st['indexed']=1;
    return $st;
}

// === INDEX HELPERS: aggiorna .index.json durante la generazione automatica ===
function idx_path_for($rel){
    global $thumbsRoot, $prefFolder;
    $rel = trim(str_replace(['\\'],'/',(string)$rel),'/');
    $dir = ($rel===''?$thumbsRoot:$thumbsRoot.'/'.implode('/',array_map(fn($seg)=>$prefFolder.$seg,explode('/',$rel))));
    if (!is_dir($dir)) @mkdir($dir,0755,true);
    return $dir.'/.index.json';
}
function write_atomic($path, $data){
    $dir = dirname($path);
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    try { $rand = bin2hex(random_bytes(6)); } catch (Throwable $e) { $rand = uniqid('', true); }
    $tmp = $dir . '/.tmp.' . $rand . '.json';
    @file_put_contents($tmp, $data, LOCK_EX);
    @chmod($tmp, 0644);
    @rename($tmp, $path); // atomic su stesso FS
}
function idx_with_lock($rel, $updater){
    $p   = idx_path_for($rel);
    $dir = dirname($p);
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    $lk = @fopen($dir.'/.index.lock', 'c');
    if ($lk) { @flock($lk, LOCK_EX); }

    $j = is_file($p) ? json_decode(@file_get_contents($p), true) : null;
    if (!is_array($j)) {
        $j = ['dir_rel'=>$rel,'generated_at'=>time(),'thumbs'=>[],
              'gif'=>['exists'=>false,'path'=>null,'mtime'=>null]];
    }

    $res = $updater($j); // muta $j in-place
    if ($res !== false) {
        write_atomic($p, json_encode($j, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    }

    if ($lk) { @flock($lk, LOCK_UN); @fclose($lk); }
}
function idx_load($rel){
    $p = idx_path_for($rel);
    $j = is_file($p) ? json_decode(@file_get_contents($p), true) : null;
    if (!is_array($j)) {
        $j = ['dir_rel'=>$rel,'generated_at'=>time(),'thumbs'=>[],'gif'=>['exists'=>false,'path'=>null,'mtime'=>null]];
    }
    return $j;
}
function idx_save($rel,$data){
    $p = idx_path_for($rel);
    write_atomic($p, json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
}
function idx_update_thumb($rel,$srcPath,$thumbPath){
    global $baseDir,$prefThumb;
    $rel = trim(str_replace(['\\'],'/',(string)$rel),'/');
    $f = basename($thumbPath);
    if (strpos($f,$prefThumb)!==0) return;
    $srcRel = ltrim(str_replace(str_replace('\\','/',$baseDir).'/', '', str_replace('\\','/',$srcPath)), '/');

    idx_with_lock($rel, function (&$j) use ($f,$srcPath,$srcRel,$thumbPath){
        $j['thumbs'][$f] = [
            'src_rel'     => $srcRel,
            'src_mtime'   => @filemtime($srcPath)?:null,
            'thumb_mtime' => @filemtime($thumbPath)?:null
        ];
        $j['generated_at'] = time();
        return true;
    });
}
function idx_update_gif($rel,$gifPath){
    $rel = trim(str_replace(['\\'],'/',(string)$rel),'/');
    $exists = is_file($gifPath);
    $mtime  = $exists ? @filemtime($gifPath) : null;

    idx_with_lock($rel, function (&$j) use ($exists,$gifPath,$mtime){
        $j['gif'] = ['exists'=>$exists, 'path'=>$exists?$gifPath:null, 'mtime'=>$mtime];
        $j['generated_at'] = time();
        return true;
    });
}

// === ENDPOINT: sincronizza cache thumbs+gif rispetto al contenuto reale ===
if (isset($_GET['sync_cache'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0'); header('Pragma: no-cache');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'method_not_allowed']); exit; }
    if ((int)$cfg['auth_mode'] >= 1) {
        if (!auth_ok()) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit; }
        $payload = json_decode(file_get_contents('php://input'), true) ?: [];
        $csrf = (string)($payload['csrf'] ?? '');
        if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf)) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'forbidden']); exit; }
    } else { $payload = json_decode(file_get_contents('php://input'), true) ?: []; }
    @set_time_limit(0);

    $targetRel = trim(str_replace(['\\'],'/', ltrim($_GET['dir'] ?? $relDir, '/')));
    if (strpos($targetRel,'..') !== false) { echo json_encode(['ok'=>false,'error'=>'bad_rel']); exit; }
    // SECURITY: Reject excluded or hidden directories from cache synchronization
    if ($targetRel !== '' && is_excluded_dir(basename($targetRel), $targetRel)) { echo json_encode(['ok'=>false,'error'=>'excluded']); exit; }
    $recursive = isset($payload['recursive']) ? (bool)$payload['recursive'] : true;

    $sum=['removed_thumbs'=>0,'removed_gifs'=>0,'removed_dirs'=>0,'regen_thumbs'=>0,'regen_gifs'=>0,'created_thumbs'=>0,'indexed'=>0,'visited'=>0];

    // se la sorgente manca ma esiste cache -> elimina
    $startSrc = ($targetRel===''?$baseDir:$baseDir.'/'.$targetRel);
    $startTh  = ($targetRel===''?$thumbsRoot:$thumbsRoot.'/'.implode('/',array_map(fn($seg)=>$prefFolder.$seg,explode('/',$targetRel))));
    if (!is_dir($startSrc) && is_dir($startTh)) { rrmdir($startTh); $sum['removed_dirs']++; }

    $stack=[$targetRel];
    while($stack){
        $rel=array_pop($stack);
        $st = sync_one_dir($rel,$baseDir,$thumbsRoot,$prefThumb,$folderGifName,$cfg);
        foreach($st as $k=>$v){ $sum[$k]+=$v; }
        $sum['visited']++;
        if($recursive){
            $srcBase=($rel===''?$baseDir:$baseDir.'/'.$rel);
            foreach(list_subdirs($srcBase,$rel) as $sd){ $stack[]=$sd['rel']; }
            // elimina thumbs senza sorgente
            $thBase=($rel===''?$thumbsRoot:$thumbsRoot.'/'.implode('/',array_map(fn($seg)=>$prefFolder.$seg,explode('/',$rel))));
            if(is_dir($thBase)){
                $scan=@scandir($thBase)?:[];
                foreach($scan as $e){
                    if($e==='.'||$e==='..') continue;
                    $absT=$thBase.'/'.$e; if(!is_dir($absT)) continue;
                    $maybeSrc = ($rel===''?$baseDir:$baseDir.'/'.$rel).'/'.preg_replace('/^'.preg_quote($prefFolder,'/').'/','',$e,1);
                    if(!is_dir($maybeSrc)){ rrmdir($absT); $sum['removed_dirs']++; }
                }
            }
        }
    }
    echo json_encode(['ok'=>true,'summary'=>$sum], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

// === SCANSIONE: sottocartelle e file immagini nella cartella corrente ===
// Performance optimization (Bolt ⚡):
// 1. Collect subdirectories and image files in a single pass of $currentAbs instead of scanning twice.
// 2. Pre-scan the thumbnail directory once into a hash set ($existingThumbs) so checking if each
//    image has a thumbnail is an O(1) in-memory lookup instead of N disk stat/file_exists calls.
$subdirs = [];
$files = [];
$dIt = @scandir($currentAbs) ?: [];
foreach ($dIt as $entry) {
    if ($entry === '.' || $entry === '..') continue;
    $abs = $currentAbs . '/' . $entry;
    if (is_dir($abs)) {
        $rel = ltrim(($relDir ? $relDir . '/' : '') . $entry, '/');
        if (is_excluded_dir($entry, $rel)) continue;
        $imgCount = 0;
        $imgSize = 0;
        $dirCount = 0;
        $scanInner = @scandir($abs) ?: [];
        foreach ($scanInner as $e2) {
            if ($e2 === '.' || $e2 === '..') continue;
            if ($e2[0] === '.') continue;
            $abs2 = $abs . '/' . $e2;
            if (is_dir($abs2)) {
                $rel2 = ltrim(($rel ? $rel . '/' : '') . $e2, '/');
                if (is_excluded_dir($e2, $rel2)) continue;
                $dirCount++;
            } elseif (is_file($abs2) && is_image_ext($abs2)) {
                $imgCount++;
                $imgSize += (int)(@filesize($abs2) ?: 0);
            }
        }
        // GIF path
        $gifAbs = ($rel === '' ? ($thumbsRoot . '/' . $folderGifName) : ($thumbsRoot . '/' . implode('/', array_map(fn($seg) => $prefFolder.$seg, explode('/', $rel))) . '/' . $folderGifName));
        // Placeholder SVG per "cartella annidata" quando non ci sono foto ma ci sono sottocartelle
        $placeholder = null;
        if (!file_exists($gifAbs) && $imgCount === 0 && $dirCount > 0) {
            $svg = '<?xml version="1.0" encoding="UTF-8"?>'
                 . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 150" preserveAspectRatio="xMidYMid slice">'
                 . '<defs>'
                 . '  <linearGradient id="bg" x1="0" y1="0" x2="0" y2="1">'
                 . '    <stop offset="0" stop-color="#eef1f5"/><stop offset="1" stop-color="#e3e6ea"/>'
                 . '  </linearGradient>'
                 . '  <linearGradient id="folder" x1="0" y1="0" x2="0" y2="1">'
                 . '    <stop offset="0" stop-color="#ffd36b"/><stop offset="1" stop-color="#f0b548"/>'
                 . '  </linearGradient>'
                 . '  <filter id="soft" x="-10%" y="-10%" width="120%" height="120%"><feGaussianBlur in="SourceAlpha" stdDeviation="2"/><feOffset dy="1"/><feComponentTransfer><feFuncA type="linear" slope="0.35"/></feComponentTransfer><feMerge><feMergeNode/><feMergeNode in="SourceGraphic"/></feMerge></filter>'
                 . '</defs>'
                 . '<rect width="200" height="150" rx="16" fill="url(#bg)"/>'
                 . '<g transform="translate(20,36)" filter="url(#soft)">'
                 . '  <!-- linguetta -->'
                 . '  <path d="M18 6h32c6 0 10 2 13 6l5 6h78a12 12 0 0 1 12 12v48a12 12 0 0 1-12 12H12A12 12 0 0 1 0 78V18A12 12 0 0 1 12 6z" fill="url(#folder)" stroke="#d39f43" stroke-width="1"/>'
                 . '  <!-- documenti stilizzati -->'
                 . '  <g opacity=".9" transform="translate(24,28)">'
                 . '    <rect x="0" y="8" width="50" height="34" rx="6" fill="#fff" stroke="#d9d9d9"/>'
                 . '    <rect x="10" y="0" width="60" height="38" rx="6" fill="#fff" stroke="#d9d9d9"/>'
                 . '  </g>'
                 . '  <!-- indice cartelle -->'
                 . '  <g opacity=".8" transform="translate(110,24)">'
                 . '    <rect x="0" y="0" width="48" height="30" rx="6" fill="#fff" stroke="#d9d9d9"/>'
                 . '    <path d="M12 9h24M12 15h24M12 21h24" stroke="#a0a0a0" stroke-width="3" stroke-linecap="round"/>'
                 . '  </g>'
                 . '</g>'
                 . '</svg>';
            $placeholder = 'data:image/svg+xml;base64,' . base64_encode($svg);
        }
        $subdirs[] = [
            'name' => $entry,
            'rel'  => $rel,
            'gif'  => file_exists($gifAbs) ? rel_url($gifAbs) : null,
            'imgs' => $imgCount,
            'size' => $imgSize,
            'dirs' => $dirCount,
            'placeholder' => $placeholder
        ];
    } elseif ($entry[0] !== '.' && is_file($abs) && is_image_ext($abs)) {
        $files[] = $abs;
    }
}
natsort($files);
$files = array_values($files);

// === PREPARA DATI ===
$items = [];
// Performance optimization (Bolt ⚡): Read existing thumbnails once into an associative lookup map.
// Replaces N file_exists() filesystem stat calls with O(1) hash lookups, speeding up gallery rendering on large albums.
$existingThumbs = is_dir($thumbDirForView) ? array_flip(@scandir($thumbDirForView) ?: []) : [];
foreach ($files as $path) {
    $name = basename($path);
    $tName = thumb_name($name);
    $thumbPath = $thumbDirForView . '/' . $tName;
    $hasThumb = isset($existingThumbs[$tName]);
    $sortDate = image_sort_timestamp($path);
    $items[] = [
        'name'     => htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        'sortName' => $name,
        'sortDate' => $sortDate,
        'sortDateLabel' => date('Y-m-d H:i:s', $sortDate),
        'sortSize' => (int)(@filesize($path) ?: 0),
        'src'      => htmlspecialchars(rel($path), ENT_QUOTES),
        'thumb'    => htmlspecialchars(rel($thumbPath), ENT_QUOTES),
        'hasThumb' => $hasThumb ? 1 : 0
    ];
}
usort($items, fn($a, $b) => ($a['sortDate'] <=> $b['sortDate']) ?: strnatcasecmp($a['sortName'], $b['sortName']));

$pageTitle = $isRoot ? $cfg['title'] : basename($relDir);
$sortLabels = $LANG === 'it'
    ? ['sort'=>'Ordina foto','date-asc'=>'Data (meno recenti)','date-desc'=>'Data (più recenti)','name-asc'=>'Nome (A-Z)','name-desc'=>'Nome (Z-A)','size-asc'=>'Dimensione (minore)','size-desc'=>'Dimensione (maggiore)','file_size'=>'Dimensione file','date_value'=>'Data','name_value'=>'Nome','size_value'=>'Dimensione']
    : ['sort'=>'Sort photos','date-asc'=>'Date (oldest first)','date-desc'=>'Date (newest first)','name-asc'=>'Name (A-Z)','name-desc'=>'Name (Z-A)','size-asc'=>'Size (smallest first)','size-desc'=>'Size (largest first)','file_size'=>'File size','date_value'=>'Date','name_value'=>'Name','size_value'=>'Size'];
$folderSortLabels = $LANG === 'it'
    ? ['sort'=>'Ordina cartelle','name-asc'=>'Nome (A-Z)','name-desc'=>'Nome (Z-A)','count-asc'=>'Meno foto','count-desc'=>'Più foto','size-asc'=>'Contenuto più piccolo','size-desc'=>'Contenuto più grande']
    : ['sort'=>'Sort folders','name-asc'=>'Name (A-Z)','name-desc'=>'Name (Z-A)','count-asc'=>'Fewest photos','count-desc'=>'Most photos','size-asc'=>'Smallest contents','size-desc'=>'Largest contents'];
?>
<!doctype html>
<html lang="<?php echo htmlspecialchars($LANG,ENT_QUOTES); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES); ?></title>
<style>
:root {
  --gap: 3px;
  --thumb-size: <?php echo (int)$cfg['thumb_view']; ?>px;
  --bg: <?php echo htmlspecialchars($cfg['bg_light'], ENT_QUOTES); ?>;
  --fg: <?php echo htmlspecialchars($cfg['fg_light'], ENT_QUOTES); ?>;
}
@media (prefers-color-scheme: dark) {
  :root {
    --bg: <?php echo htmlspecialchars($cfg['bg_dark'], ENT_QUOTES); ?>;
    --fg: <?php echo htmlspecialchars($cfg['fg_dark'], ENT_QUOTES); ?>;
  }
}
body { margin: 0; font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; background: var(--bg); color: var(--fg); }
header { padding: 12px 16px; font-weight: 600; }
.hdr {
  display:grid;
  align-items:center;
  gap:12px;
  grid-template-columns:auto 1fr auto; /* breadcrumbs | spacer | lock+menu */
}
.hdr .title {
  justify-self:center;
  text-align:center;
  font-weight:700;
}
.crumbs a { color: inherit; text-decoration: none; }
.crumbs { font-weight:600; display:flex; align-items:center; gap:6px; flex-wrap:wrap; }

/* Responsive: su schermi stretti: titolo in alto a sinistra, menu in alto a destra, breadcrumbs sotto a sinistra */
@media (max-width: 720px){
  .hdr{
    grid-template-columns: auto 1fr auto; /* title | spacer | menu */
    grid-template-rows: auto auto;       /* row1: title+menu, row2: breadcrumbs */
    align-items: center;
  }
  /* Title top-left */
  .hdr .title{
    grid-column:1; grid-row:1;
    margin:0;
    justify-self:start;
    text-align:left;
    font-size:16px;
  }
  /* Menu top-right */
  .hdr .menu{
    grid-column:3; grid-row:1;
    justify-self:end;
  }
  /* Breadcrumbs below, aligned left */
  .hdr .crumbs{
    grid-column:1 / -1; grid-row:2;
    margin-top:6px;
    justify-self:start;
  }
}

.crumbs .sep { opacity:.6; }
.grid {
  display: grid;
  gap: var(--gap);
  padding: var(--gap);
  /*grid-template-columns: repeat(auto-fill, minmax(var(--thumb-size), 1fr));*/
  grid-template-columns: repeat(auto-fill, minmax(calc(var(--thumb-size) * 1), 1fr));
}
.photo-heading {
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  margin:6px 0 0;
  padding:0 var(--gap);
  font-size:14px;
}
.photo-heading h3 { margin:0; font-size:inherit; font-weight:600; }
.photo-heading label { display:flex; align-items:center; gap:6px; font-size:12px; }
.photo-heading select { max-width:min(220px, 55vw); }
.folders-heading {
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
}
.folders-heading h3 { margin:6px 0 8px; font-size:14px; font-weight:600; }
#foldersToggle {
  display:inline-flex;
  align-items:center;
  gap:4px;
}
#foldersToggle:hover { opacity:0.85; }
#foldersToggle:focus-visible {
  outline: 2px solid #2a6fef;
  outline-offset: 2px;
  border-radius: 4px;
}
.folders-heading label { display:flex; align-items:center; gap:6px; font-size:12px; }
.folders-heading select { max-width:min(220px, 55vw); }
/* Mobile: smaller thumbnails to fit more per screen */
@media (max-width: 720px){
  .grid{
    /* reduce base cell width to ~66% of configured thumb size */
    grid-template-columns: repeat(auto-fill, minmax(calc(var(--thumb-size) * 0.66), 1fr));
    gap: 2px;
    padding: 2px;
  }
  .card{ border-radius: 8px; }
}
#grid {
  gap: 2px;
  padding: 2px;
}
.card {
  position: relative;
  border-radius: 5px;
  overflow: hidden;
  background: rgba(0,0,0,.2);
  margin: var(--gap);
  cursor:pointer;
  aspect-ratio: 1 / 1; /* celle quadrate e uniformi */
  content-visibility: auto; contain-intrinsic-size: var(--thumb-size) var(--thumb-size);
}
.card:focus-visible {
  outline: 2px solid #2a6fef;
  outline-offset: 2px;
}
.progress {
  position:absolute;
  left:10%;
  right:10%;
  top:50%;
  transform:translateY(-50%);
  height:8px;
  background:rgba(255,255,255,.25);
  border-radius:999px;
  overflow:hidden;
  display:none;
}
.progress > span {
  display:block; height:100%; width:0%;
  background:rgba(255,255,255,.9);
  transition:width .2s ease;
}
.pct {
  position:absolute; top:6px; right:10px;
  font-size:11px; color:#fff; text-shadow:0 1px 2px rgba(0,0,0,.85);
}
.topshade{
  position:absolute; left:0; right:0; top:0; height:34px;
  background:linear-gradient(to bottom, rgba(0,0,0,.6), rgba(0,0,0,0));
  display:none;
}
.card.loading .topshade{ display:block; }
.topshade .pct{ display:block; } /* forza visibilità dentro la banda */
.card.loading .progress { display:block; }
.card.loading .pct { display:block; }
.card.loading .name { opacity:.85; }
.badges{
  position:absolute; top:8px; left:8px; display:flex; gap:6px; z-index:2;
}
.badge{
  font-size:12px; line-height:1; padding:4px 6px; border-radius:999px;
  background:rgba(0,0,0,.55); color:#fff; text-shadow:0 1px 1px rgba(0,0,0,.5);
  backdrop-filter:saturate(120%) blur(2px);
}
.card img { display:block; width:100%; height:100%; object-fit:cover; transition:transform .2s ease; background: transparent; }
.card:hover img { transform: scale(1.03); }
.name{
  position:absolute; left:0; right:0; bottom:0;
  padding:6px 8px; font-size:12px; color:#fff;
  background:linear-gradient(to top, rgba(0,0,0,.65), rgba(0,0,0,0));
  text-shadow:0 1px 2px rgba(0,0,0,.85);
  white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.photo-size{
  position:absolute; top:8px; right:8px; z-index:2;
  padding:4px 7px; border-radius:999px;
  background:rgba(0,0,0,.65); color:#fff;
  font-size:12px; line-height:1.2;
  opacity:0; transform:translateY(-3px);
  transition:opacity .15s ease, transform .15s ease;
  pointer-events:none;
}
.card:hover .photo-size, .card:focus-visible .photo-size{ opacity:1; transform:none; }

/* Folder cards: darken background and subtly blur GIF to make text stand out */
#folders .card::after{
  content:"";
  position:absolute;
  inset:0;
  background:rgba(0,0,0,.35);
  z-index:1; /* sits above image, below text */
}

#folders .card img{
  filter: blur(1.5px) brightness(0.78);
  -webkit-filter: blur(1.5px) brightness(0.78);
}

@media (prefers-reduced-motion: reduce){
  #folders .card img{
    filter: brightness(0.78);
    -webkit-filter: brightness(0.78);
  }
}

/* Disable scale hover on folder GIFs */
#folders .card:hover img{ transform:none; }

/* Folder title: larger and centered, with stronger backdrop */
#folders .card .name{
  z-index:2;
  text-align:center;
  font-size:15px;
  font-weight:700;
  padding:8px 10px;
  background:linear-gradient(to top, rgba(0,0,0,.75), rgba(0,0,0,.25));
}
/* Lightbox */
.lightbox { position:fixed; inset:0; background:rgba(0,0,0,.92);
  display:none; align-items:center; justify-content:center; z-index:9999; }
.lightbox.open { display:flex; }
.spinner {
  position:absolute;
  top:50%; left:50%;
  width:48px; height:48px;
  margin:-24px 0 0 -24px;
  border:4px solid rgba(255,255,255,0.2);
  border-top-color:#fff;
  border-radius:50%;
  animation: spin 0.8s linear infinite;
  display:none;
  z-index:10000;
}
.lightbox.loading .spinner {
  display:block;
}
@keyframes spin {
  from { transform:rotate(0deg); }
  to { transform:rotate(360deg); }
}
.lb-img-wrap { max-width:95vw; max-height:90vh; }
.lb-img-wrap img { max-width:100%; max-height:90vh; display:block; margin:0 auto; }
.close,.nav { position:absolute; top:50%; transform:translateY(-50%);
  background:rgba(0,0,0,.5); border:1px solid rgba(255,255,255,.2);
  color:#fff; width:44px; height:44px; border-radius:50%;
  display:grid; place-items:center; cursor:pointer; user-select:none; }
.close { top:24px; right:24px; transform:none; }
.nav.prev { left:18px; }
.nav.next { right:18px; }
.counter { position:absolute; bottom:16px; left:50%; transform:translateX(-50%); font-size:14px; color:#bbb; }
.close:focus-visible, .nav:focus-visible, .info:focus-visible {
  outline: 2px solid #fff;
  outline-offset: 2px;
}
.card:focus-visible {
  outline: 3px solid #2a6fef;
  outline-offset: 2px;
}
button { all:unset; }
@media (hover:none){
  .card:hover img{transform:none;}
  .photo-size{display:none;}
}
.collapsed { display:none !important; }

/* --- Dropdown menu --- */
.menu{ position:relative; margin-left:auto; }
.menu-btn{
  display:flex; align-items:center; gap:8px;
  padding:8px 10px; border:1px solid rgba(255,255,255,.2);
  background:rgba(0,0,0,.35); color:#fff; border-radius:10px; cursor:pointer;
  user-select:none;
}
@media (prefers-color-scheme: light){
  .menu-btn{ background:rgba(0,0,0,.06); color:inherit; border-color:rgba(0,0,0,.12); }
}
.menu-btn .ic{ font-size:16px; line-height:1; }
.menu-list{
  position:absolute; top:calc(100% + 8px); right:0; min-width:220px;
  background:rgba(22,22,22,.96); color:#fff; border:1px solid rgba(255,255,255,.18);
  border-radius:12px; box-shadow:0 12px 28px rgba(0,0,0,.35);
  padding:6px; display:none; z-index:1000;
}
@media (prefers-color-scheme: light){
  .menu-list{ background:#fff; color:#111; border-color:rgba(0,0,0,.12); box-shadow:0 12px 28px rgba(0,0,0,.18); }
}
.menu.open .menu-list{ display:block; }
.menu-item{
  display:flex; align-items:center; gap:10px; padding:10px 10px;
  border-radius:10px; text-decoration:none; color:inherit; white-space:nowrap;
}
.menu-item:hover{ background:rgba(255,255,255,.10); }
@media (prefers-color-scheme: light){
  .menu-item:hover{ background:rgba(0,0,0,.06); }
}
.menu-sep{ height:1px; margin:6px; background:rgba(255,255,255,.12); }
/* --- Auth indicator --- */
.auth-ind{
  display:flex; align-items:center; justify-content:center;
  gap:6px; padding:6px 8px; border-radius:8px; line-height:1;
  font-size:16px; user-select:none; margin-right:8px;
}
.auth-ind.unlocked{ color:#15a34a; } /* green */
.auth-ind.locked{ color:#dc2626; }   /* red */
@media (prefers-color-scheme: light){
  .auth-ind.unlocked{ color:#15803d; }
  .auth-ind.locked{ color:#b91c1c; }
}
.info{
  position:absolute; top:24px; left:24px;
  background:rgba(0,0,0,.55); border:1px solid rgba(255,255,255,.2);
  color:#fff; width:44px; height:44px; border-radius:50%;
  display:grid; place-items:center; cursor:pointer; user-select:none;
}
.exif{
  position:absolute; left:16px; bottom:16px; max-width:min(520px,90vw);
  background:rgba(0,0,0,.65); color:#fff; border:1px solid rgba(255,255,255,.2);
  border-radius:12px; padding:10px 12px; font-size:13px; line-height:1.35;
  backdrop-filter:saturate(120%) blur(4px); display:none;
}
.exif.open{ display:block; }
/* --- Sync/Purge overlay --- */
.opov{
  position:fixed; inset:0; z-index:10000;
  background:rgba(0,0,0,.65);
  display:none; align-items:center; justify-content:center;
  backdrop-filter:saturate(120%) blur(2px);
}
.opov.open{ display:flex; }
.opbox{
  background:rgba(22,22,22,.95);
  color:#fff; border:1px solid rgba(255,255,255,.18);
  padding:16px 18px; border-radius:12px; min-width:min(520px, 92vw);
  box-shadow:0 14px 38px rgba(0,0,0,.45);
}
@media (prefers-color-scheme: light){
  .opbox{ background:#fff; color:#111; border-color:rgba(0,0,0,.12); }
}
.oprow{ display:flex; align-items:center; gap:12px; }
.opspin{
  width:26px; height:26px; border-radius:50%;
  border:3px solid rgba(255,255,255,.25); border-top-color:#fff;
  animation: spin .8s linear infinite;
}
@media (prefers-color-scheme: light){
  .opspin{ border:3px solid rgba(0,0,0,.12); border-top-color:#111; }
}
.opmsg{ font-weight:600; }
.opsub{ font-size:12px; opacity:.8; margin-top:6px; }
.exif .h{ font-weight:700; margin-bottom:6px; font-size:13px; }
.exif .g{ display:grid; grid-template-columns:auto 1fr; gap:6px 12px; }
.exif .k{ opacity:.8; }
.exif .v{ white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
@media (max-width:560px){ .exif{ left:8px; right:8px; bottom:8px; max-width:unset; } }
</style>
</head>
<body>
<?php
  // Config pubblica per il client: non includere segreti
  $publicCfg = $cfg;
  unset($publicCfg['auth_password']);   // rimuovi password/hash
?>
<script>
  // Prevent DOM XSS / script breakage when embedding variables in inline script tags
  window.__I18N__ = <?php echo json_encode($I18N, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
  window.__GALLERY_CFG__ = <?php echo json_encode($publicCfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
  window.__CUR_DIR__ = <?php echo json_encode($relDir, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
  window.__SELF__ = <?php echo json_encode($self, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
  window.__AUTH_MODE__ = <?php echo (int)$cfg['auth_mode']; ?>;
  window.__CSRF__ = <?php echo json_encode($CSRF_TOKEN, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
</script>
<header>
  <div class="hdr">
    <nav class="crumbs" aria-label="Breadcrumb">
      <a href="<?php echo htmlspecialchars($self,ENT_QUOTES); ?>" title="<?php echo htmlspecialchars(t('home'),ENT_QUOTES); ?>">🏠 <?php echo htmlspecialchars(t('home'),ENT_QUOTES); ?></a>
      <?php
        if (!$isRoot) {
          $acc = '';
          foreach (explode('/', $relDir) as $i => $seg) {
            $acc = ltrim($acc . '/' . $seg, '/');
            if (is_excluded_dir($seg, $acc)) break;
            echo '<span class="sep">›</span><a href="'.$self.'?dir='.rawurlencode($acc).'">'.htmlspecialchars($seg,ENT_QUOTES).'</a>';
          }
        }
      ?>
    </nav>
    <div class="title"><?php echo htmlspecialchars($cfg['title'],ENT_QUOTES); ?></div>
    <div class="menu" id="userMenu">
      <div style="display:flex;align-items:center;gap:6px;">
        <?php if ((int)$cfg['auth_mode'] === 1): ?>
          <div class="auth-ind <?php echo auth_ok() ? 'unlocked' : 'locked'; ?>" role="status" aria-live="polite"
               title="<?php echo htmlspecialchars(auth_ok() ? t('auth_extra') : t('auth_no'),ENT_QUOTES); ?>">
            <?php echo auth_ok() ? '🔓' : '🔒'; ?>
          </div>
        <?php elseif ((int)$cfg['auth_mode'] === 2 && auth_ok()): ?>
          <div class="auth-ind unlocked" role="status" aria-live="polite" title="<?php echo htmlspecialchars(t('auth_in'),ENT_QUOTES); ?>">
            🔓
          </div>
        <?php endif; ?>
        <button class="menu-btn" id="menuToggle" aria-haspopup="true" aria-expanded="false">
          <span class="ic">☰</span><span><?php echo htmlspecialchars(t('menu'),ENT_QUOTES); ?></span>
        </button>
      </div>
      <div class="menu-list" role="menu" aria-label="<?php echo htmlspecialchars(t('menu'),ENT_QUOTES); ?>">
        <?php if ((int)$cfg['auth_mode'] === 0): ?>
          <a href="#" id="purgeBtn" class="menu-item" role="menuitem" title="<?php echo htmlspecialchars(t('purge_t'),ENT_QUOTES); ?>">
            <span class="ic">🧹</span><span><?php echo htmlspecialchars(t('purge'),ENT_QUOTES); ?></span>
          </a>
          <a href="#" id="syncBtn" class="menu-item" role="menuitem" title="<?php echo htmlspecialchars(t('sync_t'),ENT_QUOTES); ?>">
            <span class="ic">🔄</span><span><?php echo htmlspecialchars(t('sync'),ENT_QUOTES); ?></span>
          </a>
        <?php elseif ((int)$cfg['auth_mode'] >= 1 && auth_ok()): ?>
          <a href="#" id="purgeBtn" class="menu-item" role="menuitem" title="<?php echo htmlspecialchars(t('purge_t'),ENT_QUOTES); ?>">
            <span class="ic">🧹</span><span><?php echo htmlspecialchars(t('purge'),ENT_QUOTES); ?></span>
          </a>
          <a href="#" id="syncBtn" class="menu-item" role="menuitem" title="<?php echo htmlspecialchars(t('sync_t'),ENT_QUOTES); ?>">
            <span class="ic">🔄</span><span><?php echo htmlspecialchars(t('sync'),ENT_QUOTES); ?></span>
          </a>
          <a href="#" id="mkHash" class="menu-item" role="menuitem">
            <span class="ic">🔐</span><span><?php echo htmlspecialchars(t('mkhash'),ENT_QUOTES); ?></span>
          </a>
          <div class="menu-sep" aria-hidden="true"></div>
          <a href="?logout=1" class="menu-item" role="menuitem">
            <span class="ic">🚪</span><span><?php echo htmlspecialchars(t('logout'),ENT_QUOTES); ?></span>
          </a>
        <?php elseif ((int)$cfg['auth_mode'] >= 1 && !auth_ok()): ?>
          <a href="#" id="btnLogin" class="menu-item" role="menuitem">
            <span class="ic">🔑</span><span><?php echo htmlspecialchars(t('login'),ENT_QUOTES); ?></span>
          </a>
        <?php endif; ?>
        <?php if ((int)$cfg['auth_mode'] === 0 || auth_ok()): ?>
          <div class="menu-sep" aria-hidden="true"></div>
          <label class="menu-item" for="langSel">
            <span class="ic">🌐</span><span><?php echo htmlspecialchars(t('language'),ENT_QUOTES); ?></span>
            <select id="langSel" aria-label="<?php echo htmlspecialchars(t('language'),ENT_QUOTES); ?>" style="margin-left:auto;max-width:160px;">
              <option value="auto"<?php echo $cfg['language'] === 'auto' ? ' selected' : ''; ?>><?php echo htmlspecialchars(t('auto'),ENT_QUOTES); ?></option>
              <?php foreach (mpg_lang_langs() as $lc => $ln): ?>
                <option value="<?php echo htmlspecialchars($lc,ENT_QUOTES); ?>"<?php echo $cfg['language'] === $lc ? ' selected' : ''; ?>><?php echo htmlspecialchars($ln,ENT_QUOTES); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        <?php endif; ?>
      </div>
    </div>
  </div>
</header>

<!-- Operation overlay (sync/purge feedback) -->
<div id="opOverlay" class="opov" aria-live="polite" aria-busy="true" hidden>
  <div class="opbox">
    <div class="oprow">
      <div class="opspin" aria-hidden="true"></div>
      <div>
        <div id="opMsg" class="opmsg"><?php echo htmlspecialchars(t('sync_title'),ENT_QUOTES); ?></div>
        <div id="opSub" class="opsub"><?php echo htmlspecialchars(t('sync_sub'),ENT_QUOTES); ?></div>
      </div>
    </div>
  </div>
</div>

<?php if (!empty($subdirs)): ?>
<section style="padding:0 var(--gap);">
  <div class="folders-heading">
    <h3 id="foldersToggle" role="button" tabindex="0" aria-expanded="true" aria-controls="folders" style="cursor:pointer; user-select:none;">
      <span id="foldersCaret" aria-hidden="true">▾</span> <?php echo htmlspecialchars(t('folders'),ENT_QUOTES); ?>
    </h3>
    <label for="folderSort"><?php echo htmlspecialchars($folderSortLabels['sort'],ENT_QUOTES); ?>
      <select id="folderSort">
        <?php foreach (['name-asc','name-desc','count-asc','count-desc','size-asc','size-desc'] as $sortOption): ?>
          <option value="<?php echo htmlspecialchars($sortOption,ENT_QUOTES); ?>"><?php echo htmlspecialchars($folderSortLabels[$sortOption],ENT_QUOTES); ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </div>
  <div class="grid" id="folders">
    <?php foreach ($subdirs as $sd): ?>
      <a class="card" href="<?php echo htmlspecialchars($self,ENT_QUOTES); ?>?dir=<?php echo rawurlencode($sd['rel']); ?>" data-rel="<?php echo htmlspecialchars($sd['rel'],ENT_QUOTES); ?>" data-has-imgs="<?php echo ($sd['imgs']>0)?'1':'0'; ?>" data-imgs="<?php echo (int)$sd['imgs']; ?>" data-size="<?php echo (int)$sd['size']; ?>" data-name="<?php echo htmlspecialchars($sd['name'],ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" style="display:block; text-decoration:none; color:inherit;">
        <img
          src="<?php echo $sd['gif'] ? htmlspecialchars($sd['gif'],ENT_QUOTES) : ($sd['placeholder'] ? $sd['placeholder'] : 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw=='); ?>"
          alt="<?php echo htmlspecialchars($sd['name'],ENT_QUOTES); ?>"
          data-gif="<?php echo htmlspecialchars($sd['gif'] ?? '',ENT_QUOTES); ?>"
          loading="lazy" decoding="async">
        <div class="badges">
          <?php if ($sd['dirs'] > 0): ?><span class="badge" title="<?php echo htmlspecialchars(t('folders'),ENT_QUOTES); ?>">📁 <?php echo (int)$sd['dirs']; ?></span><?php endif; ?>
          <?php if ($sd['imgs'] > 0): ?><span class="badge" title="<?php echo htmlspecialchars(t('photos'),ENT_QUOTES); ?>">🖼 <?php echo (int)$sd['imgs']; ?></span><?php endif; ?>
        </div>
        <div class="name" title="<?php echo htmlspecialchars($sd['name'],ENT_QUOTES); ?>"><?php echo htmlspecialchars($sd['name'],ENT_QUOTES); ?></div>
        <div class="progress"><span></span></div>
        <div class="topshade"><div class="pct">0%</div></div>
      </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php if (empty($items) && empty($subdirs)): ?>
<p style="padding:16px"><?php echo htmlspecialchars(t('no_images'),ENT_QUOTES); ?></p>
<?php elseif (!empty($items)): ?>
<div class="photo-heading">
  <h3><?php echo htmlspecialchars(t('photos'),ENT_QUOTES); ?> (<?php echo count($items); ?>)</h3>
  <label for="photoSort"><?php echo htmlspecialchars($sortLabels['sort'],ENT_QUOTES); ?>
    <select id="photoSort">
      <?php foreach (['date-asc','date-desc','name-asc','name-desc','size-asc','size-desc'] as $sortOption): ?>
        <option value="<?php echo htmlspecialchars($sortOption,ENT_QUOTES); ?>"><?php echo htmlspecialchars($sortLabels[$sortOption],ENT_QUOTES); ?></option>
      <?php endforeach; ?>
    </select>
  </label>
</div>
<main class="grid" id="grid">
<?php foreach ($items as $idx => $it): ?>
  <figure class="card" data-idx="<?php echo $idx; ?>" tabindex="0" role="button" aria-label="<?php echo htmlspecialchars($it['name'], ENT_QUOTES, 'UTF-8'); ?>">
    <div class="photo-size" aria-hidden="true"></div>
    <img
      src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw=="
      data-thumb="<?php echo $it['thumb']; ?>"
      data-name="<?php echo $it['name']; ?>"
      data-has-thumb="<?php echo (int)$it['hasThumb']; ?>"
      alt="<?php echo $it['name']; ?>"
      loading="lazy"
      decoding="async"
    >
    <figcaption class="name" title="<?php echo $it['name']; ?>"><?php echo $it['name']; ?></figcaption>
  </figure>
<?php endforeach; ?>
</main>
<?php endif; ?>

<div class="lightbox" id="lightbox" role="dialog" aria-modal="true" aria-label="<?php echo htmlspecialchars(t('viewer'),ENT_QUOTES); ?>" aria-hidden="true">
  <div class="spinner" id="spinner" aria-hidden="true"></div>
  <button class="close" id="btnClose" title="<?php echo htmlspecialchars(t('close'),ENT_QUOTES); ?>" aria-label="<?php echo htmlspecialchars(t('close'),ENT_QUOTES); ?>">✕</button>
  <button class="nav prev" id="btnPrev" title="<?php echo htmlspecialchars(t('prev'),ENT_QUOTES); ?>" aria-label="<?php echo htmlspecialchars(t('prev'),ENT_QUOTES); ?>">❮</button>
  <div class="lb-img-wrap"><img id="lbImg" alt=""></div>
  <button class="nav next" id="btnNext" title="<?php echo htmlspecialchars(t('next'),ENT_QUOTES); ?>" aria-label="<?php echo htmlspecialchars(t('next'),ENT_QUOTES); ?>">❯</button>
  <button class="info" id="btnInfo" title="<?php echo htmlspecialchars(t('info'),ENT_QUOTES); ?>" aria-label="<?php echo htmlspecialchars(t('info'),ENT_QUOTES); ?>" aria-controls="exifPanel" aria-expanded="false">ℹ︎</button>
  <div class="exif" id="exifPanel" aria-live="polite"></div>
  <div class="counter" id="counter"></div>
</div>

<!-- Modal login per auth_mode >= 1 -->
<div id="loginModal" style="position:fixed;inset:0;display:none;align-items:center;justify-content:center;background:rgba(0,0,0,.55);z-index:10000;">
  <div style="background:#1b1b1b;color:#fff;border:1px solid rgba(255,255,255,.2);border-radius:12px;box-shadow:0 12px 32px rgba(0,0,0,.5);padding:16px;min-width:280px;max-width:90vw;">
    <div style="font-weight:700;margin-bottom:10px;"><?php echo htmlspecialchars(t('login_required'),ENT_QUOTES); ?></div>
    <label for="loginPass" style="font-size:12px;opacity:.8;"><?php echo htmlspecialchars(t('password'),ENT_QUOTES); ?></label>
    <input id="loginPass" type="password" placeholder="<?php echo htmlspecialchars(t('password'),ENT_QUOTES); ?>" style="width:100%;padding:10px 12px;margin-top:6px;border-radius:8px;border:1px solid #333;background:#0f0f0f;color:#fff;" autocomplete="current-password" autocapitalize="none" autocorrect="off" spellcheck="false" inputmode="text">
    <div id="loginErr" style="height:14px;color:#ffb3b3;font-size:12px;margin-top:6px;"></div>
    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px;">
      <button id="loginCancel" style="padding:8px 10px;border-radius:8px;border:1px solid #333;background:#222;color:#fff;cursor:pointer;"><?php echo htmlspecialchars(t('cancel'),ENT_QUOTES); ?></button>
      <button id="loginSubmit" style="padding:8px 10px;border-radius:8px;border:1px solid #333;background:#2a6fef;color:#fff;cursor:pointer;"><?php echo htmlspecialchars(t('login'),ENT_QUOTES); ?></button>
    </div>
  </div>
</div>

<script>
(() => {
  // Prevent DOM XSS / script breakage when embedding array items in inline script tags
  const I18N = window.__I18N__ || {};
  const L = (k, p) => { let s = I18N[k] || k; if (p) for (const n in p) s = s.split('{' + n + '}').join(String(p[n])); return s; };
  const syncSummary = (s) => L('sync_sum', { v: s.visited||0, c: s.created_thumbs||0, r: s.regen_thumbs||0, x: s.removed_thumbs||0, g: s.regen_gifs||0, y: s.removed_gifs||0 });
  window.__L__ = L; window.__syncSummary__ = syncSummary;
  const items = <?php echo json_encode($items, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); ?>;
  const sortFieldLabels = <?php echo json_encode(['date'=>$sortLabels['date_value'],'name'=>$sortLabels['name_value'],'size'=>$sortLabels['size_value']], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
  const grid = document.getElementById('grid');
  const sortSelect = document.getElementById('photoSort');
  const sortEntries = grid ? items.map((item, index) => ({item, card: grid.children[index]})) : [];
  const lb = document.getElementById('lightbox');
  const spinner = document.getElementById('spinner');
  const lbImg = document.getElementById('lbImg');
  const counter = document.getElementById('counter');
  const btnClose = document.getElementById('btnClose');
  const btnPrev = document.getElementById('btnPrev');
  const btnNext = document.getElementById('btnNext');
  const btnInfo = document.getElementById('btnInfo');
  const exifPanel = document.getElementById('exifPanel');
  const folders = document.getElementById('folders');
  let idx = -1;

  // Performance optimization (Bolt ⚡): Cache Intl.Collator for natural name sorting.
  // Calling string.localeCompare() with options inside Array.prototype.sort creates a new Collator
  // per comparison (O(N log N)), taking seconds on large galleries. Reusing a single Collator is ~25x faster.
  const naturalCollator = (typeof Intl !== 'undefined' && Intl.Collator)
    ? new Intl.Collator(undefined, {numeric:true, sensitivity:'base'})
    : null;
  const naturalCompare = (a, b) => naturalCollator
    ? naturalCollator.compare(a, b)
    : a.localeCompare(b, undefined, {numeric:true, sensitivity:'base'});

  function applyPhotoSort(value) {
    if (!grid || !sortSelect) return;
    const [key, direction] = value.split('-');
    const multiplier = direction === 'desc' ? -1 : 1;
    const sorted = sortEntries.slice().sort((a, b) => {
      const difference = key === 'date'
        ? a.item.sortDate - b.item.sortDate
        : key === 'size'
          ? a.item.sortSize - b.item.sortSize
          : naturalCompare(a.item.sortName, b.item.sortName);
      return difference === 0 ? naturalCompare(a.item.sortName, b.item.sortName) : difference * multiplier;
    });
    items.length = 0;
    sorted.forEach((entry, index) => {
      items.push(entry.item);
      entry.card.dataset.idx = index;
      grid.appendChild(entry.card);
    });
  }
  // Performance optimization (Bolt ⚡): Cache Intl.NumberFormat to avoid expensive re-instantiation on every file badge render (~40x faster)
  let fileSizeFormatter = null;
  function formatFileSize(bytes) {
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let value = bytes;
    let unit = 0;
    while (value >= 1024 && unit < units.length - 1) {
      value /= 1024;
      unit++;
    }
    if (!fileSizeFormatter) {
      fileSizeFormatter = new Intl.NumberFormat(document.documentElement.lang, {maximumFractionDigits:1});
    }
    return `${fileSizeFormatter.format(value)} ${units[unit]}`;
  }
  function updateHoverValues() {
    const key = (sortSelect?.value || 'date-asc').split('-')[0];
    sortEntries.forEach(({item, card}) => {
      const value = key === 'date' ? item.sortDateLabel : key === 'name' ? item.sortName : formatFileSize(item.sortSize);
      const label = `${sortFieldLabels[key]}: ${value}`;
      const badge = card.querySelector('.photo-size');
      if (badge) badge.textContent = label;
      card.title = `${item.sortName} — ${label}`;
    });
  }
  if (sortSelect) {
    const sortStorageKey = `mpg_sort_${window.__CUR_DIR__ || ''}`;
    const savedSort = localStorage.getItem(sortStorageKey);
    if (Array.from(sortSelect.options).some(option => option.value === savedSort)) sortSelect.value = savedSort;
    applyPhotoSort(sortSelect.value);
    updateHoverValues();
    sortSelect.addEventListener('change', () => {
      localStorage.setItem(sortStorageKey, sortSelect.value);
      applyPhotoSort(sortSelect.value);
      updateHoverValues();
    });
  } else updateHoverValues();

  const folderSortSelect = document.getElementById('folderSort');
  if (folders && folderSortSelect) {
    const folderCards = Array.from(folders.querySelectorAll('a.card'));
    const folderSortStorageKey = `mpg_folder_sort_${window.__CUR_DIR__ || ''}`;
    function applyFolderSort(value) {
      const [key, direction] = value.split('-');
      const multiplier = direction === 'desc' ? -1 : 1;
      const sorted = folderCards.slice().sort((a, b) => {
        let difference;
        if (key === 'count') difference = Number(a.dataset.imgs) - Number(b.dataset.imgs);
        else if (key === 'size') difference = Number(a.dataset.size) - Number(b.dataset.size);
        else difference = naturalCompare(a.dataset.name, b.dataset.name);
        if (difference === 0 && key !== 'name') {
          return naturalCompare(a.dataset.name, b.dataset.name);
        }
        return difference * multiplier;
      });
      sorted.forEach(card => folders.appendChild(card));
    }
    const savedFolderSort = localStorage.getItem(folderSortStorageKey);
    if (Array.from(folderSortSelect.options).some(option => option.value === savedFolderSort)) folderSortSelect.value = savedFolderSort;
    applyFolderSort(folderSortSelect.value);
    folderSortSelect.addEventListener('change', () => {
      localStorage.setItem(folderSortStorageKey, folderSortSelect.value);
      applyFolderSort(folderSortSelect.value);
    });
  }

  // Dropdown menu
  const menu = document.getElementById('userMenu');
  const menuToggle = document.getElementById('menuToggle');
  function closeMenu(){ menu?.classList.remove('open'); menuToggle?.setAttribute('aria-expanded','false'); }
  function toggleMenu(){ 
    if (!menu) return;
    const open = menu.classList.toggle('open');
    menuToggle?.setAttribute('aria-expanded', open ? 'true' : 'false');
  }
  menuToggle?.addEventListener('click', (e)=>{ e.preventDefault(); e.stopPropagation(); toggleMenu(); });
  document.addEventListener('click', (e)=>{ if (!menu?.contains(e.target)) closeMenu(); });
  document.addEventListener('keydown', (e)=>{ if (e.key === 'Escape') closeMenu(); });

  // Purge cache corrente (ricorsivo dalla root)
  const purgeBtn = document.getElementById('purgeBtn');
  purgeBtn?.addEventListener('click', (e)=>{
    e.preventDefault();
    const isRoot = !window.__CUR_DIR__;
    const msg = isRoot
      ? L('c_purge_all')
      : L('c_purge_dir');
    if (!confirm(msg)) return;

    const url = withDir(`${window.__SELF__}?purge_cache=1&t=${Date.now()}`);
    const body = { recursive: isRoot ? 1 : 0 };
    if ((window.__AUTH_MODE__ || 0) >= 1) { body.csrf = window.__CSRF__ || ''; }

    fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      cache: 'no-store',
      body: JSON.stringify(body)
    })
    .then(r=>r.json())
    .then(d=>{
      if (d && d.ok) {
        alert(L('purge_done', { dirs: d.purged.dirs, thumbs: d.purged.thumbs, gifs: d.purged.gifs }));
        location.reload();
      } else {
        alert(L('e_purge'));
      }
    }).catch(()=>alert(L('e_purge')));
  });

// Sincronizza cache
const syncBtn = document.getElementById('syncBtn');
syncBtn?.addEventListener('click', (e)=>{
  e.preventDefault();
  const isRoot = !window.__CUR_DIR__;
  const msg = isRoot
    ? L('c_sync_all')
    : L('c_sync_dir');
  if (!confirm(msg)) return;

  const url = withDir(`${window.__SELF__}?sync_cache=1&t=${Date.now()}`);
  const body = { recursive: 1 };
  if ((window.__AUTH_MODE__ || 0) >= 1) body.csrf = window.__CSRF__ || '';

  fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    cache: 'no-store',
    body: JSON.stringify(body)
  })
  .then(r=>r.json())
  .then(d=>{
    if (d && d.ok) {
      const s=d.summary||{};
      alert(L('sync_done') + '\n' + syncSummary(s));
      location.reload();
    } else {
      alert(L('e_sync'));
    }
  })
  .catch(()=>alert(L('e_sync')));
});

  // Collassa/espandi “Cartelle”
  const toggle = document.getElementById('foldersToggle');
  const caret  = document.getElementById('foldersCaret');
  const keyColl = `mpg_fc_${window.__CUR_DIR__ || ''}`;
  function applyFoldState(){
    const st = localStorage.getItem(keyColl) || 'open';
    const isClosed = st === 'closed';
    if (isClosed){ folders?.classList.add('collapsed'); if(caret) caret.textContent='▸'; }
    else { folders?.classList.remove('collapsed'); if(caret) caret.textContent='▾'; }
    toggle?.setAttribute('aria-expanded', isClosed ? 'false' : 'true');
  }
  function toggleFolders(){
    folders?.classList.toggle('collapsed');
    const closed = Boolean(folders && folders.classList.contains('collapsed'));
    if (caret) caret.textContent = closed ? '▸' : '▾';
    toggle?.setAttribute('aria-expanded', closed ? 'false' : 'true');
    localStorage.setItem(keyColl, closed ? 'closed' : 'open');
  }
  applyFoldState();
  toggle?.addEventListener('click', toggleFolders);
  toggle?.addEventListener('keydown', (e)=>{
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      toggleFolders();
    }
  });

  // Utility per endpoint con dir
  function withDir(url) {
    const d = window.__CUR_DIR__ || '';
    if (!d) return url;
    return url + (url.includes('?') ? '&' : '?') + 'dir=' + encodeURIComponent(d);
  }

  // Handler Login (auth_mode >= 1) - modal con input password
  const loginBtn = document.getElementById('btnLogin');
  const loginModal = document.getElementById('loginModal');
  const loginPass  = document.getElementById('loginPass');
  const loginSubmit= document.getElementById('loginSubmit');
  const loginCancel= document.getElementById('loginCancel');
  const loginErr   = document.getElementById('loginErr');

  function openLogin(){
    if (!loginModal) return;
    loginErr && (loginErr.textContent = '');
    loginPass && (loginPass.value = '');
    loginModal.style.display = 'flex';
    setTimeout(()=>{ loginPass?.focus(); }, 0);
  }
  function closeLogin(){
    if (!loginModal) return;
    loginModal.style.display = 'none';
  }
  loginBtn?.addEventListener('click', (e)=>{
    e.preventDefault();
    openLogin();
  });
  loginCancel?.addEventListener('click', (e)=>{ e.preventDefault(); closeLogin(); });
  loginModal?.addEventListener('click', (e)=>{ if (e.target === loginModal) closeLogin(); });
  document.addEventListener('keydown', (e)=>{ if (e.key === 'Escape') closeLogin(); });

  function doLogin(){
    const pwd = (loginPass?.value || '').toString();
    if (!pwd) { if (loginErr) loginErr.textContent = L('enter_pwd'); return; }
    fetch(`${window.__SELF__}?do_login=1`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ pass: pwd })
    })
    .then(r=>r.json())
    .then(d=>{
      if (d && d.ok) { location.reload(); }
      else { if (loginErr) loginErr.textContent = L('denied'); }
    })
    .catch(()=>{ if (loginErr) loginErr.textContent = L('auth_err'); });
  }
  loginSubmit?.addEventListener('click', (e)=>{ e.preventDefault(); doLogin(); });
  loginPass?.addEventListener('keydown', (e)=>{ if (e.key === 'Enter') { e.preventDefault(); doLogin(); } });

  // Selettore lingua: salva la scelta in .config.json
  document.getElementById('langSel')?.addEventListener('change', (e) => {
    const body = { lang: e.target.value };
    if ((window.__AUTH_MODE__ || 0) >= 1) body.csrf = window.__CSRF__ || '';
    fetch(`${window.__SELF__}?set_lang=1`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      cache: 'no-store',
      body: JSON.stringify(body)
    })
    .then(r => r.json())
    .then(d => { if (d && d.ok) location.reload(); else alert(L('e_generic')); })
    .catch(() => alert(L('e_generic')));
  });

  // Handler Crea hash
  const mkHashLink = document.getElementById('mkHash');
  mkHashLink?.addEventListener('click', (e) => {
    e.preventDefault();
    const pwd = prompt(L('hash_prompt'));
    if (!pwd) return;
    fetch(`${window.__SELF__}?mkhash=1`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      cache: 'no-store',
      body: JSON.stringify({ pass: pwd, csrf: window.__CSRF__ || '' })
    })
      .then(r=>r.json())
      .then(d=>{
        if (d && d.ok && d.hash) {
          navigator.clipboard?.writeText(d.hash).catch(()=>{});
          alert(L('hash_done', { hash: d.hash }));
        } else {
          alert(L('e_hash'));
        }
      }).catch(()=>{ alert(L('e_generic')); });
  });

  // Lightbox
  let lastFocused = null;
  function openAt(i){
    idx = (i + items.length) % items.length;
    if (!lb.classList.contains('open')) {
      lastFocused = document.activeElement;
    }
    lb.classList.add('open','loading');
    lb.setAttribute('aria-hidden', 'false');
    spinner.style.display = 'block';
    const item = items[idx];
    const src = item.src;
    lbImg.src = '';
    lbImg.alt = item.name;
    counter.textContent = (idx+1) + ' / ' + items.length;
    if (exifPanel) { exifPanel.classList.remove('open'); exifPanel.innerHTML=''; }
    btnInfo?.setAttribute('aria-expanded', 'false');
    fetch(withDir(`${window.__SELF__}?exif=${encodeURIComponent(item.name)}`), {cache:'no-store'})
      .then(r=>r.json()).then(d=>{ if (items[idx] === item) renderExif(d, item); })
      .catch(()=>{ if (items[idx] === item) renderExif(null, item); });
    document.body.style.overflow = 'hidden';
    const tmp = new Image();
    tmp.onload = () => {
      lbImg.src = src;
      lb.classList.remove('loading');
      spinner.style.display = 'none';
    };
    tmp.onerror = () => {
      lb.classList.remove('loading');
      spinner.style.display = 'none';
    };
    tmp.src = src;
    setTimeout(() => { btnClose.focus(); }, 0);
  }
  function closeLb(){
    lb.classList.remove('open');
    lb.setAttribute('aria-hidden', 'true');
    btnInfo?.setAttribute('aria-expanded', 'false');
    document.body.style.overflow = '';
    lbImg.src = '';
    if (lastFocused && typeof lastFocused.focus === 'function') {
      lastFocused.focus();
    }
  }
  function prev(){ openAt(idx-1); }
  function next(){ openAt(idx+1); }

  grid?.addEventListener('click', e => {
    const fig = e.target.closest('.card');
    if (!fig) return;
    if (e.altKey) {
      const img = fig.querySelector('img');
      fetch(withDir(`${window.__SELF__}?diag=${encodeURIComponent(img.dataset.name)}`), {cache:'no-store'})
        .then(r=>r.json()).then(d=>console.table(d));
      e.preventDefault(); return;
    }
    const i = parseInt(fig.dataset.idx, 10);
    if (!Number.isNaN(i)) openAt(i);
  });
  grid?.addEventListener('keydown', e => {
    if (e.key === 'Enter' || e.key === ' ') {
      const fig = e.target.closest('.card');
      if (fig && grid.contains(fig)) {
        e.preventDefault();
        const i = parseInt(fig.dataset.idx, 10);
        if (!Number.isNaN(i)) openAt(i);
      }
    }
  });
  btnClose.addEventListener('click', closeLb);
  btnPrev.addEventListener('click', prev);
  btnNext.addEventListener('click', next);
  lb.addEventListener('click', e => { if (e.target === lb) closeLb(); });
  const lbControls = [btnClose, btnPrev, btnNext, btnInfo];
  window.addEventListener('keydown', e => {
    if (!lb.classList.contains('open')) return;
    if (e.key === 'Tab') {
      const focusables = lbControls.filter(el => el && el.offsetParent !== null);
      if (!focusables.length) return;
      const first = focusables[0];
      const last = focusables[focusables.length - 1];
      if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
      }
    } else if (e.key === 'Escape') closeLb();
    else if (e.key === 'ArrowLeft') prev();
    else if (e.key === 'ArrowRight') next();
    else if (e.key && e.key.toLowerCase() === 'i') { e.preventDefault(); toggleExif(); }
  });
  // EXIF panel
  function renderExif(d, item){
    if (!exifPanel) return;
    // SECURITY: Use safe DOM methods and textContent to prevent DOM XSS from image filenames or EXIF metadata
    exifPanel.textContent = '';
    const h = document.createElement('div');
    h.className = 'h';
    const g = document.createElement('div');
    g.className = 'g';

    if (!d || !d.ok) {
      h.textContent = 'Info';
      const k = document.createElement('div');
      k.className = 'k';
      k.textContent = 'EXIF';
      const v = document.createElement('div');
      v.className = 'v';
      v.textContent = L('exif_na');
      g.append(k, v);
    } else {
      h.textContent = d.name || 'Info';
      const f = d.fields || {};
      for (const k of Object.keys(f)) {
        const val = Array.isArray(f[k]) ? f[k].join(', ') : String(f[k]);
        const kEl = document.createElement('div');
        kEl.className = 'k';
        kEl.textContent = k;
        const vEl = document.createElement('div');
        vEl.className = 'v';
        vEl.title = val;
        vEl.textContent = val;
        g.append(kEl, vEl);
      }
    }
    const sizeKey = document.createElement('div');
    sizeKey.className = 'k';
    sizeKey.textContent = L('file_size');
    const sizeValue = document.createElement('div');
    sizeValue.className = 'v';
    sizeValue.textContent = formatFileSize(item?.sortSize || 0);
    g.append(sizeKey, sizeValue);
    exifPanel.append(h, g);
  }
  function toggleExif(){
    if (!exifPanel) return;
    const isOpen = exifPanel.classList.toggle('open');
    btnInfo?.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
  }
  btnInfo?.addEventListener('click', (e)=>{ e.preventDefault(); toggleExif(); });

  // === Generazione asincrona GIF per cartelle ===
  if (folders) {
    const cards = Array.from(folders.querySelectorAll('a.card'));
    const pollers = new Map(); // rel -> interval id

    function startPoll(rel, card) {
      if (pollers.has(rel)) return;
      const bar = card.querySelector('.progress > span');
      const pct = card.querySelector('.pct');
      card.classList.add('loading');
      // reset UI
      if (bar) bar.style.width = '1%';
      if (pct) pct.textContent = '0%';

      const iv = setInterval(() => {
        fetch(withDir(`${window.__SELF__}?gif_progress=${encodeURIComponent(rel)}&t=${Date.now()}`), { cache:'no-store' })
          .then(r=>r.ok ? r.json() : null)
          .then(d=>{
            if (!d || !d.ok) return;
            if (!d.exists) return; // progress file non ancora creato
            const total = d.total || 0, done = d.done || 0;
            if (total > 0) {
              const perc = Math.max(0, Math.min(100, Math.round((done / total) * 100)));
              if (bar) bar.style.width = (perc || 1) + '%';
              if (pct) pct.textContent = perc + '%';
              if (done >= total) {
                clearInterval(iv); pollers.delete(rel);
                // non rimuovere subito; lascia che il fetch make_gif imposti la GIF
              }
            }
          }).catch(()=>{});
      }, 350);
      pollers.set(rel, iv);
    }

    cards.forEach(card => {
      const img = card.querySelector('img');
      const rel = card.dataset.rel || '';
      const hasImgs = card.dataset.hasImgs === '1';
      if (img.dataset.gif && img.dataset.gif.length) return; // già pronto
      if (!hasImgs) return; // niente foto: non generare gif
      startPoll(rel, card);
      fetch(withDir(`${window.__SELF__}?make_gif=${encodeURIComponent(rel)}&t=${Date.now()}`), { cache:'no-store' })
        .then(r=>r.json())
        .then(d=>{
          if (d && d.ok && d.gif) {
            img.src = d.gif + '&cb=' + Date.now();
            card.classList.remove('loading');
          }
        })
        .catch(()=>{})
        .finally(()=>{
          const iv = pollers.get(rel);
          if (iv) { clearInterval(iv); pollers.delete(rel); }
          const bar = card.querySelector('.progress > span'); if (bar) bar.style.width = '100%';
          const pct = card.querySelector('.pct'); if (pct) pct.textContent = '100%';
          //setTimeout(()=>{ card.classList.remove('loading'); }, 300);
        });
    });
  }

  // === Generazione asincrona thumbnails ===
  const CONCURRENCY = 10;
  const imgs = Array.from(document.querySelectorAll('.card img'));
  const queue = imgs.filter(img => img.dataset.hasThumb === '0').map(img => ({
    name: img.dataset.name,
    img
  }));

  // Lazy-load per thumbs già esistenti
  const io = ('IntersectionObserver' in window) ? new IntersectionObserver((entries, obs) => {
    for (const en of entries) {
      if (!en.isIntersecting) continue;
      const im = en.target;
      if (im.dataset.hasThumb === '1' && im.dataset.thumb) {
        // carica solo quando visibile
        if (!im.dataset.loaded) {
          im.src = im.dataset.thumb;
          im.dataset.loaded = '1';
        }
        obs.unobserve(im);
      }
    }
  }, { rootMargin: '200px' }) : null;
  
  // attacca l'observer a tutte le immagini
  imgs.forEach(img => { io && io.observe(img); });

  let active = 0;
  function nextJob() {
    if (queue.length === 0) return;
    if (active >= CONCURRENCY) return;
    const job = queue.shift();
    active++;
    fetch(withDir(`${window.__SELF__}?make_thumb=${encodeURIComponent(job.name)}`), { cache: 'no-store' })
      .then(r => r.json())
      .then(data => {
        if (data && data.ok && data.thumb) {
          job.img.src = data.thumb + `?v=${Date.now()}`;
          job.img.dataset.hasThumb = '1';
          if (data && data.exif === false) { job.img.setAttribute('data-exif-missing','1'); }
        } else if (data && data.error) {
          console.warn('Thumb fail', job.name, data.error);
        }
      })
      .catch(() => {})
      .finally(() => {
        active--;
        nextJob();
      });

    // avvia altri job fino alla concorrenza
    while (active < CONCURRENCY && queue.length > 0) {
      nextJob();
    }
  }
  // avvio
  for (let i = 0; i < CONCURRENCY; i++) nextJob();
})();
</script>
<script>
(function(){
  const L = window.__L__, syncSummary = window.__syncSummary__;
  const elOv  = document.getElementById('opOverlay');
  const elMsg = document.getElementById('opMsg');
  const elSub = document.getElementById('opSub');

  function openOverlay(msg, sub){
    if (!elOv) return;
    elMsg.textContent = msg || L('sync_title');
    elSub.textContent = sub || '';
    elOv.classList.add('open');
    elOv.removeAttribute('hidden');
  }
  function setOverlay(msg, sub){
    if (!elOv) return;
    if (msg) elMsg.textContent = msg;
    if (typeof sub !== 'undefined') elSub.textContent = sub;
  }
  function closeOverlay(){
    if (!elOv) return;
    elOv.classList.remove('open');
    elOv.setAttribute('hidden','');
  }

  async function postJSON(url, body){
    const headers = { 'Content-Type':'application/json' };
    if ((window.__AUTH_MODE__|0) >= 1) {
      body = Object.assign({ csrf: window.__CSRF__ }, body || {});
    }
    const res = await fetch(url, { method:'POST', headers, body: JSON.stringify(body||{}) });
    const json = await res.json().catch(()=>({ok:false,error:'bad_json'}));
    if (!res.ok) throw Object.assign(new Error(json.error||res.statusText), { json, status: res.status });
    return json;
  }

  // Feedback durante "Sincronizza cache"
  const syncBtn = document.getElementById('syncBtn');
  if (syncBtn) {
    syncBtn.addEventListener('click', async (e)=>{
      e.preventDefault();
      e.stopImmediatePropagation(); // evita eventuali handler duplicati
      openOverlay(L('sync_title'), L('sync_sub'));
      try{
        const url = window.__SELF__ + '?sync_cache=1&dir=' + encodeURIComponent(window.__CUR_DIR__ || '');
        const res = await postJSON(url, { recursive: true });
        const s = res.summary || {};
        setOverlay(L('done'), syncSummary(s));
        setTimeout(()=>{ closeOverlay(); location.reload(); }, 1100);
      } catch(err){
        const msg = (err && err.json && err.json.error) ? err.json.error : (err.message || L('e_generic'));
        setOverlay(L('e_sync'), msg);
        setTimeout(()=> closeOverlay(), 1600);
      }
    });
  }

  // opzionale: esponi helper se servono altrove
  window.__mpgOverlay__ = { open: openOverlay, set: setOverlay, close: closeOverlay };
})();
</script>
</body>
</html>
