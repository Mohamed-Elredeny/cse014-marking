<?php
/** api/labs.php?do=list|setState|setRubric|schedule|setSchedule */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

require_login();
$do = $_GET['do'] ?? 'list';

switch ($do) {

    case 'list':
        ok(['labs' => labs_all()]);

    /* ---- the Main TA opens and closes by hand (null = follow the schedule) ---- */
    case 'setState': {
        require_main();
        require_post();

        $id    = (int)want('labId');
        $state = inp('state');            // 'open' | 'closed' | null

        if (!one('SELECT id FROM labs WHERE id = ?', [$id])) fail(L('err.labNotFound'));
        if ($state !== null && !in_array($state, ['open', 'closed'], true)) fail(L('err.badState'));

        q('UPDATE labs SET state_override = ? WHERE id = ?', [$state, $id]);

        audit(
            $state === null ? 'lab.schedule' : ($state === 'open' ? 'lab.open' : 'lab.close'),
            (string)$id, 'lab'
        );

        ok(['lab' => lab_get($id)]);
    }

    /* ---- editing a lab's rubric ---- */
    case 'setRubric': {
        require_main();
        require_post();

        $labId = (int)want('labId');
        $items = inp('items', []);
        $recompute = inp('recompute', true);

        if (!one('SELECT id FROM labs WHERE id = ?', [$labId])) fail(L('err.labNotFound'));
        if (!is_array($items) || !$items) fail(L('err.rubricMin'));
        if (count($items) > 20) fail(L('err.rubricMax'));

        $clean = [];
        $seen = [];
        foreach ($items as $i => $it) {
            $text   = trim((string)($it['text'] ?? ''));
            $points = (int)($it['points'] ?? 0);

            if ($text === '')                 fail(L('err.rubricNoText', ['n' => $i + 1]));
            if (mb_strlen($text) > 255)       fail(L('err.rubricTextLong'));
            if ($points < 0 || $points > 100) fail(L('err.rubricPoints'));

            // Keep the existing code so recorded grades stay tied to the item.
            $code = (string)($it['id'] ?? $it['code'] ?? '');
            if (!preg_match('/^[a-z0-9_]{1,10}$/i', $code) || isset($seen[$code])) {
                $k = 1;
                do { $code = 'r' . $k++; } while (isset($seen[$code]));
            }
            $seen[$code] = true;
            $clean[] = ['code' => $code, 'text' => $text, 'points' => $points];
        }

        $total = array_sum(array_column($clean, 'points'));
        if ($total <= 0) fail(L('err.rubricTotal'));

        $changed = 0;
        db()->beginTransaction();
        try {
            q('DELETE FROM lab_rubric WHERE lab_id = ?', [$labId]);
            $ins = db()->prepare('INSERT INTO lab_rubric (lab_id, code, text, points, sort)
                                  VALUES (?,?,?,?,?)');
            foreach ($clean as $s => $c) $ins->execute([$labId, $c['code'], $c['text'], $c['points'], $s]);

            // Recorded grades were computed with the old points — recompute
            // them from the criteria stored against each mark.
            if ($recompute) {
                $pts = array_column($clean, 'points', 'code');
                $upd = db()->prepare('UPDATE marks SET grade = ? WHERE id = ?');
                foreach (all('SELECT id, items, grade, status FROM marks WHERE lab_id = ?', [$labId]) as $m) {
                    if ($m['status'] === 'absent') continue;
                    $has = json_decode($m['items'] ?: '[]', true) ?: [];
                    if (!$has) continue;                      // manual grade — leave it alone
                    $g = 0;
                    foreach ($has as $c) $g += $pts[$c] ?? 0;
                    if ($g !== (int)$m['grade']) { $upd->execute([$g, $m['id']]); $changed++; }
                }
            }
            db()->commit();
        } catch (Throwable $e) {
            db()->rollBack();
            error_log('[marking] setRubric: ' . $e->getMessage());
            fail(L('err.saveFailed'), 500);
        }

        audit('rubric.save', (string)$labId, 'lab', '',
              count($clean) . ' items / total ' . $total
              . ($changed ? ' / recomputed ' . $changed : ''));

        ok(['lab' => lab_get($labId), 'recomputed' => $changed]);
    }

    case 'schedule': {
        $sch = schedule_get();
        $out = [];
        foreach (all('SELECT id, state_override FROM labs ORDER BY id') as $l) {
            $w = lab_window($sch, (int)$l['id']);
            $out[] = [
                'id'       => (int)$l['id'],
                'state'    => lab_state($sch, $l),
                'derived'  => lab_state($sch, ['id' => $l['id'], 'state_override' => null]),
                'override' => $l['state_override'],
                'from'     => $w['from'] * 1000,
                'to'       => $w['to'] * 1000,
            ];
        }
        ok($sch + ['labs' => $out]);
    }

    case 'setSchedule': {
        require_main();
        require_post();

        $sch   = schedule_get();
        $start = (string)inp('startDate', $sch['startDate']);
        $days  = (int)inp('weekDays', $sch['weekDays']);
        $auto  = inp('auto', $sch['auto']);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !strtotime($start)) {
            fail(L('err.badDate'));
        }

        $new = [
            'startDate' => $start,
            'weekDays'  => max(1, min(30, $days)),
            'auto'      => (bool)$auto,
        ];
        set_setting('schedule', $new);

        audit('schedule.save', '', '', '',
              $new['startDate'] . ' / ' . $new['weekDays'] . 'd / '
              . ($new['auto'] ? 'auto' : 'manual'));

        ok(['schedule' => $new]);
    }
}

fail(L('err.unknownAction'), 404);
