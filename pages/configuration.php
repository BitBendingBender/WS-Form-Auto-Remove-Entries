<?php

// this is not really nice but it doesnt really matter
use WS_Form_ARE\WS_Form_ARE;

$success = "";

if(isset($_GET['clearlog'])) {
    file_put_contents(WS_Form_ARE::LOG_FILE, '');
}

if(isset($_POST['run'])) WS_Form_ARE::runCleanupCron();

if (isset($_POST['sent'])) {

    foreach($_POST['config'] as $key => &$value) {
        if($value === 'true') $value = true;
        if($value === 'false') $value = false;
    }

    // merge old config with new config and safe to file
    WS_Form_ARE::$CONFIG = array_merge(WS_Form_ARE::$CONFIG, ($_POST['config'] ?? []));

    WS_Form_ARE::saveConfig(WS_Form_ARE::$CONFIG);
    WS_Form_ARE::loadConfig();

    $success = "Konfiguration aktualisiert";

}

?>
<div class="wrap">
    <h1>WS Form Auto Remove Old Entries</h1>
    <form method="post">
        <h2>Cleaner Ausführen</h2>
        <p>Achtung: Wenn der Dry-Mode nicht aktiv ist, werden sofort alle Einträge die älter als <?= WS_Form_ARE::$CONFIG['retention-days'] ?> sind gelöscht.</p>
        <button type="submit" value="run" name="run" style="background: red; color: white; font-weight: bold; border: none; padding: 8px 16px;">Ausführen</button>
    </form>
    <form method="post">
        <h2>Konfiguration</h2>
        <div class="form-group">
            <label for="retention-days">Beiträge löschen die Älter als X Tage sind</label>
            <input type="number" min="1" max="365" id="retention-days" name="config[retention-days]" value="<?= WS_Form_ARE::$CONFIG['retention-days'] ?? 1 ?>">
        </div>
        <div class="form-group">
            <label for="run-always">Bei jedem Seitenaufruf prüfen</label>
            <input type="hidden" name="config[run-always]" value="false">
            <input type="checkbox" id="run-always" name="config[run-always]" value="true" <?= ((bool)WS_Form_ARE::$CONFIG['run-always'] ?? false) ? 'checked' : '' ?>>
        </div>
        <div class="form-group">
            <label for="in-dry-mode">Läuft im Dry-Mode (es werden keine Einträge gelöscht)</label>
            <input type="hidden" name="config[in-dry-mode]" value="false">
            <input type="checkbox" id="in-dry-mode" name="config[in-dry-mode]" value="true" <?= ((bool)WS_Form_ARE::$CONFIG['in-dry-mode'] ?? true) ? 'checked' : '' ?>>
        </div>
        <br>
        <button type="submit" name="sent" value="sent">Speichern</button>

        <?php if($success) { ?>
            <div class="notification-success">
                <?= $success ?>
            </div>
        <?php } ?>

    </form>
    <h2>Plugin Log <a href="admin.php?page=ws-form-auto-remove-entries&clearlog">(Log leeren)</a></h2>
    <div class="log-file">
        <?= nl2br(file_get_contents(WS_Form_ARE::LOG_FILE)) ?>
    </div>
    <?php if(isset($_GET['clearlog'])) { ?>
        <script>
            window.location.href = 'admin.php?page=ws-form-auto-remove-entries';
        </script>
    <?php } ?>
</div>

<style>
    .notification-success {
        padding: 16px;
        margin-top: 16px;
        text-align: center;
        font-weight: bold;
        background: rgba(38, 201, 139, 0.2);
        max-width: 320px;
    }
    .log-file {
        padding: 8px;
        border-radius: 4px;
        background: white;
        max-height: 460px;
        overflow: auto;
    }
</style>