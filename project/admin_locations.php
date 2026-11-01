<?php
// Admin: register and manage MY parking places (public or private).
// Every query is limited to places owned by the logged-in admin (owner_username).
require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/dbx.php';
require_once __DIR__ . '/src/locations.php';
require_once __DIR__ . '/src/ui.php';

[$admin, $zone] = require_admin();
$now = app_now();
$ready = new_schema_ready();

$errors = [];
$form = null; // values shown in the form when an error happens or an edit is opened
$editId = (int) ($_GET['edit'] ?? 0);

if ($ready && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        set_location_active(pdo(), $admin, $id, ((string) ($_POST['active'] ?? '0')) === '1', $now);
        header('Location: ' . app_url('admin_locations.php?saved=toggled'));
        exit;
    }

    if ($action === 'save') {
        $id = (int) ($_POST['id'] ?? 0);
        [$errors, $clean] = validate_location_input($_POST);
        if (!$errors) {
            $result = $id > 0
                ? update_location(pdo(), $admin, $id, $clean, $now)
                : create_location(pdo(), $admin, $zone, $clean, $now);
            if ($result['ok']) {
                header('Location: ' . app_url('admin_locations.php?saved=' . ($id > 0 ? 'updated' : 'added')));
                exit;
            }
            $errors[] = $result['error'];
        }
        $form = $_POST;
        $editId = $id;
    }
}

if ($ready && $form === null && $editId > 0) {
    $own = get_owner_location(pdo(), $admin, $editId);
    if ($own === null) {
        $editId = 0;
    } else {
        $form = [
            'name' => $own['name'], 'kind' => $own['kind'], 'city' => $own['city'], 'address' => $own['address'],
            'description' => $own['description'], 'capacity' => $own['capacity'], 'hourly_rate' => $own['hourly_rate'],
            'lat' => $own['lat'] ?? '', 'lng' => $own['lng'] ?? '',
            'open_from' => $own['open_from'] ? substr((string) $own['open_from'], 0, 5) : '',
            'open_until' => $own['open_until'] ? substr((string) $own['open_until'], 0, 5) : '',
            'pay_modes_present' => '1', 'pay_online' => !empty($own['accepts_online'] ?? 1) ? '1' : '', 'pay_cash' => !empty($own['accepts_cash'] ?? 1) ? '1' : '',
        ];
    }
}
$f = static fn(string $k, string $default = ''): string => (string) (($form ?? [])[$k] ?? $default);

$places = $ready ? list_owner_locations(pdo(), $admin) : [];
$savedMsg = ['added' => 'Parking place added. Users can now find and reserve it.', 'updated' => 'Parking place updated.', 'toggled' => 'Availability changed.'][$_GET['saved'] ?? ''] ?? '';

ui_page_start('My parking places', 'admin', 'locations', $admin);
?>
<h1>My parking places</h1>
<?php if ($savedMsg !== '') { echo ui_msg('ok', $savedMsg); } ?>
<?php foreach ($errors as $err) { echo ui_msg('err', $err); } ?>

<?php if (!$ready): ?>
    <?= ui_schema_missing_notice() ?>
<?php else: ?>
    <form class="card" method="post" action="admin_locations.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int) $editId ?>">
        <h2 style="margin-top:0"><?= $editId > 0 ? 'Edit parking place' : 'Register a new parking place' ?></h2>
        <div class="row">
            <div><label for="name">Name</label><input type="text" id="name" name="name" required maxlength="100" value="<?= e($f('name')) ?>"></div>
            <div><label for="kind">Type</label>
                <select id="kind" name="kind">
                    <?php foreach (LOCATION_KINDS as $k => $label): ?>
                        <option value="<?= e($k) ?>"<?= $f('kind', 'public') === $k ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div><label for="city">City / town</label>
                <select id="city" name="city" required>
                    <?php $curCity = (string) $f('city', ''); foreach (array_keys(FP_CITIES) as $cn): ?>
                        <option value="<?= e($cn) ?>"<?= (fp_canonical_city($curCity) ?? '') === $cn ? ' selected' : '' ?>><?= e($cn) ?></option>
                    <?php endforeach; ?>
                    <?php if ($curCity !== '' && fp_canonical_city($curCity) === null): ?>
                        <option value="<?= e($curCity) ?>" selected><?= e($curCity) ?> (other town)</option>
                    <?php endif; ?>
                </select></div>
        </div>
        <label for="address">Address</label><input type="text" id="address" name="address" required maxlength="255" value="<?= e($f('address')) ?>">
        <label for="description">Description (optional)</label><textarea id="description" name="description" rows="2" maxlength="500"><?= e($f('description')) ?></textarea>
        <div class="row">
            <div><label for="capacity">Spaces (cars at the same time)</label><input type="number" id="capacity" name="capacity" required min="1" max="<?= (int) MAX_LOCATION_CAPACITY ?>" value="<?= e($f('capacity', '1')) ?>"></div>
            <div><label for="hourly_rate">Price per hour (&#8377;)</label><input type="number" id="hourly_rate" name="hourly_rate" required min="1" max="<?= (int) MAX_HOURLY_RATE ?>" value="<?= e($f('hourly_rate', '20')) ?>"></div>
            <div><label for="lat">Latitude (optional)</label><input type="text" id="lat" name="lat" value="<?= e($f('lat')) ?>" placeholder="e.g. 16.1170"></div>
            <div><label for="lng">Longitude (optional)</label><input type="text" id="lng" name="lng" value="<?= e($f('lng')) ?>" placeholder="e.g. 74.2110"></div>
            <div><label for="open_from">Opens (leave both empty for 24 hours)</label><input type="time" id="open_from" name="open_from" value="<?= e($f('open_from')) ?>"></div>
            <div><label for="open_until">Closes</label><input type="time" id="open_until" name="open_until" value="<?= e($f('open_until')) ?>"></div>
        </div>
        <?php
            $modesSent = isset(($form ?? [])['pay_modes_present']);
            $onChecked = $modesSent ? !empty($form['pay_online']) : true;
            $cashChecked = $modesSent ? !empty($form['pay_cash']) : true;
        ?>
        <?php if (notify_schema_ready(pdo())): ?>
        <fieldset style="border:1px solid #ddd;border-radius:8px;margin:12px 0;padding:10px 14px">
            <legend>How can customers pay?</legend>
            <input type="hidden" name="pay_modes_present" value="1">
            <label style="display:block;font-weight:normal"><input type="checkbox" name="pay_online" value="1"<?= $onChecked ? ' checked' : '' ?>> Online (UPI, cards, net banking) &ndash; confirmed automatically</label>
            <label style="display:block;font-weight:normal"><input type="checkbox" name="pay_cash" value="1"<?= $cashChecked ? ' checked' : '' ?>> At the place (cash or UPI to you) &ndash; you mark it as received in Reservations</label>
        </fieldset>
        <?php else: ?>
        <p class="muted">Payment methods: customers can pay online and at the place. (To choose per place, apply database migration 002 &ndash; see the README.)</p>
        <?php endif; ?>
        <p class="muted">Changing the price affects new reservations only. You cannot lower the number of spaces below what is already reserved at one time in the future.</p>
        <button class="btn" type="submit"><?= $editId > 0 ? 'Save changes' : 'Add parking place' ?></button>
        <?php if ($editId > 0): ?><a href="admin_locations.php">Cancel</a><?php endif; ?>
    </form>

    <h2>Registered places</h2>
    <?php if (!$places): ?>
        <div class="card">You have not registered any parking place yet. Use the form above.</div>
    <?php else: ?>
        <div class="tablewrap"><table>
            <tr><th>Place</th><th>Type</th><th>Price</th><th>Pays</th><th>Spaces</th><th>In use now</th><th>Hours</th><th>Status</th><th></th></tr>
            <?php foreach ($places as $p):
                $inUse = location_peak_usage(pdo(), (int) $p['id'], $now->format(PK_DT), $now->modify('+1 minute')->format(PK_DT), $now);
                $active = (int) $p['is_active'] === 1;
            ?>
                <tr>
                    <td><strong><?= e($p['name']) ?></strong><div class="muted"><?= e($p['address']) ?>, <?= e($p['city']) ?> <?= ui_map_link($p['lat'], $p['lng']) ?></div></td>
                    <td><?= ui_badge((string) $p['kind']) ?></td>
                    <td><?= ui_money((int) $p['hourly_rate']) ?>/h</td>
                    <td class="muted"><?= implode(' + ', array_filter([!empty($p['accepts_online'] ?? 1) ? 'Online' : '', !empty($p['accepts_cash'] ?? 1) ? 'At place' : ''])) ?></td>
                    <td><?= (int) $p['capacity'] ?></td>
                    <td><?= (int) $inUse ?></td>
                    <td><?= ($p['open_from'] && $p['open_until']) ? e(substr((string) $p['open_from'], 0, 5)) . ' &ndash; ' . e(substr((string) $p['open_until'], 0, 5)) : '24 h' ?></td>
                    <td><?= ui_badge($active ? 'active' : 'inactive') ?></td>
                    <td>
                        <a class="btn small alt" href="admin_locations.php?edit=<?= (int) $p['id'] ?>">Edit</a>
                        <form class="inline" method="post" action="admin_locations.php">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                            <input type="hidden" name="active" value="<?= $active ? '0' : '1' ?>">
                            <button class="btn small<?= $active ? ' danger' : '' ?>" type="submit"><?= $active ? 'Switch off' : 'Switch on' ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table></div>
        <p class="muted">Switching a place off stops new reservations; existing reservations stay valid.</p>
    <?php endif; ?>
<?php endif; ?>
</div></body></html>
