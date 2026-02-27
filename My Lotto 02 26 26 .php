{source}
<?php
// this is the mylottoexpert entire code live 
defined('_JEXEC') or die;

// --------------------------------------------------
// Bootstrap: Get common Joomla objects (no "use" in Sorcerer)
// --------------------------------------------------
$app  = \Joomla\CMS\Factory::getApplication();
$user = \Joomla\CMS\Factory::getUser();
$db   = \Joomla\CMS\Factory::getDbo();

if ($user->guest) {
    $app->enqueueMessage('You must be logged in to view or save your predictions.', 'error');
    $app->redirect(\Joomla\CMS\Uri\Uri::base() . 'index.php?option=com_users&view=login');
    exit;
}
// --------------------------------------------------
// Helper Functions (must be in the first PHP block for Sorcerer)
// --------------------------------------------------
/**
 * User profile prefs (stored in #__user_profiles)
 */
function getUserPref($db, $userId, $key, $default = '1') {
    $query = $db->getQuery(true)
        ->select($db->quoteName('profile_value'))
        ->from($db->quoteName('#__user_profiles'))
        ->where($db->quoteName('user_id') . ' = ' . (int)$userId)
        ->where($db->quoteName('profile_key') . ' = ' . $db->quote('mylotto.' . $key))
        ->order($db->quoteName('ordering') . ' ASC')
        ->setLimit(1);
    $db->setQuery($query);
    $val = $db->loadResult();
    if ($val === null || $val === '') return $default;
    $decoded = json_decode($val, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        if (is_string($decoded) || is_numeric($decoded)) {
            return (string)$decoded;
        }
        if (is_bool($decoded)) {
            return $decoded ? '1' : '0';
        }
        // If decoded is array/object/null, do not return "Array"
        return (string)$default;
    }
    return (string)$val;
}

function setUserPref($db, $userId, $key, $value) {
    $value = (string)$value;
    $query = $db->getQuery(true)
        ->select('COUNT(*)')
        ->from($db->quoteName('#__user_profiles'))
        ->where($db->quoteName('user_id') . ' = ' . (int)$userId)
        ->where($db->quoteName('profile_key') . ' = ' . $db->quote('mylotto.' . $key));
    $db->setQuery($query);
    $exists = (int)$db->loadResult() > 0;

    if ($exists) {
        $query = $db->getQuery(true)
            ->update($db->quoteName('#__user_profiles'))
            ->set($db->quoteName('profile_value') . ' = ' . $db->quote(json_encode($value)))
            ->where($db->quoteName('user_id') . ' = ' . (int)$userId)
            ->where($db->quoteName('profile_key') . ' = ' . $db->quote('mylotto.' . $key));
    } else {
        $columns = ['user_id','profile_key','profile_value','ordering'];
        $values  = [
            (int)$userId,
            $db->quote('mylotto.' . $key),
            $db->quote(json_encode($value)),
            0
        ];
        $query = $db->getQuery(true)
            ->insert($db->quoteName('#__user_profiles'))
            ->columns(array_map([$db,'quoteName'], $columns))
            ->values(implode(',', $values));
    }
    $db->setQuery($query);
    $db->execute();
}

/**
 * Auto-create the #__skai_best_settings persistence table if it does not exist.
 * Called once per page load; DDL is cheap when table already exists.
 */
function ensureSkaiTable($db) {
    $prefix = $db->getPrefix();
    $tbl    = $prefix . 'skai_best_settings';
    $sql    = "CREATE TABLE IF NOT EXISTS `{$tbl}` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`          INT UNSIGNED NOT NULL,
  `lottery_id`       INT UNSIGNED NOT NULL,
  `settings_json`    MEDIUMTEXT   NOT NULL DEFAULT '',
  `settings_summary` VARCHAR(500) NOT NULL DEFAULT '',
  `notes`            TEXT         NOT NULL DEFAULT '',
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_lottery` (`user_id`,`lottery_id`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    try { $db->setQuery($sql)->execute(); } catch (Exception $e) {}
}

/**
 * Derive a concise SKAI-voice mode label from a saved run row.
 * Shows only the run mode (e.g. "Auto-tune", "Manual") and appends
 * " - Custom" when the user explicitly overrode individual settings
 * (auto_tune = 0 for skai_prediction, or any non-default params for other sources).
 * Does NOT show specific hyperparameter values.
 */
function buildBestSettingsSummary(array $row, array $methodLabels)
{
    $src = '';
    if (isset($row['source']) && $row['source'] !== null) {
        $src = (string)$row['source'];
    }

    $label = '';
    if (isset($methodLabels[$src])) {
        $label = (string)$methodLabels[$src];
    } else {
        $label = ucwords(str_replace('_', ' ', $src));
    }

    // If a template name was saved on the run, prefer that.
$templateName = '';
if (isset($row['template_name']) && $row['template_name'] !== null && $row['template_name'] !== '') {
    $templateName = (string)$row['template_name'];
} elseif (isset($row['settings_template_name']) && $row['settings_template_name'] !== null && $row['settings_template_name'] !== '') {
    $templateName = (string)$row['settings_template_name'];
}
    if ($templateName !== '') {
        return $label . ' - ' . $templateName;    }

    // For skai_prediction, append the run mode (e.g. Auto, Manual) but do not call it custom.
    if ($src === 'skai_prediction') {
$mode = '';
if (isset($row['skai_run_mode']) && $row['skai_run_mode'] !== null) {
    $mode = trim((string)$row['skai_run_mode']);
}
        $modeLabel = $mode !== '' ? ucwords(str_replace('_', ' ', $mode)) : 'Default';
        return $label . ' - ' . $modeLabel;
    }

    // For other methods simply return the method label.  Default parameters are common,
// so do not append "Custom" unless a template explicitly names it.
    return $label;
}
// --------------------------------------------------------------
// New Features toggle (persisted in #__user_profiles)
// Stored as '1' (show) / '0' (hide).
// We can safely call helpers before their textual definition (PHP parses file first).
$showNewFeatures = getUserPref($db, (int) $user->id, 'showNewFeatures', '1') === '1';

// Allow POST toggle via form
if ($app->input->getMethod() === 'POST' && $app->input->post->get('toggle_new_features', '', 'STRING') !== '') {
    if (!\Joomla\CMS\Session\Session::checkToken('post')) {
        die('Invalid Token');
    }
    $newVal = $app->input->post->get('new_features_enabled', '0', 'STRING') === '1' ? '1' : '0';
    setUserPref($db, (int) $user->id, 'showNewFeatures', $newVal);
    $showNewFeatures = ($newVal === '1');
}
// --------------------------------------------------
// Prediction List: Recency Sort Preference (persisted in #__user_profiles)
// --------------------------------------------------
$predSortPref = getUserPref($db, (int)$user->id, 'predSort', 'desc'); // 'desc' = newest-to-oldest (default)
$predSortDir  = (strtolower($predSortPref) === 'asc') ? 'ASC' : 'DESC';

// Ensure persistence table exists (idempotent DDL)
ensureSkaiTable($db);

// --------------------------------------------------
// Handle Prediction Deletion / Template Actions
// IMPORTANT: Only enforce CSRF when THIS page's actions are triggered.
// This avoids breaking unrelated module POSTs that may target this URL.
// --------------------------------------------------

if ($app->input->getMethod() === 'POST') {

    // Detect whether this POST is intended for mylottoexpert actions
    $hasDashboardAction =
        ($app->input->post->getInt('delete_set', 0) > 0) ||
        ($app->input->post->get('delete_all_predictions', '', 'STRING') !== '') ||
        ($app->input->post->get('delete_lottery_predictions', '', 'STRING') !== '') ||
        ($app->input->post->getInt('delete_setting_template', 0) > 0) ||
        ($app->input->post->get('save_setting_template', '', 'STRING') !== '') ||
        ($app->input->post->get('save_best_settings', '', 'STRING') !== '') ||
        ($app->input->post->get('save_best_bundle', '', 'STRING') !== '') ||
        ($app->input->post->get('toggle_new_features', '', 'STRING') !== '');

    if ($hasDashboardAction && !\Joomla\CMS\Session\Session::checkToken('post')) {
        $app->enqueueMessage('Your session expired. Please refresh and try again.', 'error');
        $app->redirect(\Joomla\CMS\Uri\Uri::getInstance()->toString());
        exit;
    }

    // Delete single prediction (hardened: use Joomla input filtering)
    $id = $app->input->post->getInt('delete_set', 0);
    if ($id > 0) {

        $q = $db->getQuery(true)

            ->delete($db->quoteName('#__user_saved_numbers'))
            ->where($db->quoteName('id') . ' = ' . $id)
            ->where($db->quoteName('user_id') . ' = ' . (int) $user->id);

        $db->setQuery($q);
        try {
            $db->execute();
            $app->enqueueMessage('Prediction deleted.', 'message');
            $app->redirect(\Joomla\CMS\Uri\Uri::getInstance()->toString());
        } catch (Exception $e) {
            $app->enqueueMessage('Error deleting: ' . $e->getMessage(), 'error');
        }
    }

    // Delete all predictions (hardened: use Joomla input filtering)
    $doDeleteAll = $app->input->post->get('delete_all_predictions', '', 'STRING') !== '';
    if ($doDeleteAll) {
        $q = $db->getQuery(true)
            ->delete($db->quoteName('#__user_saved_numbers'))
            ->where($db->quoteName('user_id') . ' = ' . (int) $user->id);


        $db->setQuery($q);
        try {
            $db->execute();
            $app->enqueueMessage('All predictions deleted.', 'message');
            $app->redirect(\Joomla\CMS\Uri\Uri::getInstance()->toString());
        } catch (Exception $e) {
            $app->enqueueMessage('Error deleting all: ' . $e->getMessage(), 'error');
        }
    }

// Delete all predictions for a single lottery + draw date (group delete) - hardened input
$doGroupDelete = $app->input->post->get('delete_lottery_predictions', '', 'STRING') !== '';
$lotteryId     = $app->input->post->getInt('lottery_id', 0);
$drawDateRaw   = $app->input->post->getString('draw_date', '');
if ($doGroupDelete && $lotteryId && $drawDateRaw !== '') {

    $ts = strtotime((string)$drawDateRaw);
    if ($ts === false) {
        $app->enqueueMessage('Invalid draw date. No predictions deleted.', 'error');
        $app->redirect(\Joomla\CMS\Uri\Uri::getInstance()->toString());
        exit;
    }
    $drawDate = date('Y-m-d', $ts); // normalize

    $q = $db->getQuery(true)
        ->delete($db->quoteName('#__user_saved_numbers'))
        ->where($db->quoteName('user_id') . ' = ' . (int)$user->id)
        ->where($db->quoteName('lottery_id') . ' = ' . (int)$lotteryId)
        // compare only date portion of next_draw_date
        ->where('DATE(' . $db->quoteName('next_draw_date') . ') = ' . $db->quote($drawDate));
    $db->setQuery($q);
    try {
        $db->execute();
        $app->enqueueMessage('Deleted all predictions for this draw.', 'message');
        $app->redirect(\Joomla\CMS\Uri\Uri::getInstance()->toString());
    } catch (Exception $e) {
        $app->enqueueMessage('Error deleting group: ' . $e->getMessage(), 'error');
    }
}

// Delete saved setting template (hardened: use Joomla input filtering)
$id = $app->input->post->getInt('delete_setting_template', 0);
if ($id > 0) {

        // $id already sanitized above
        $q = $db->getQuery(true)
            ->delete($db->quoteName('#__user_saved_settings'))

            ->where($db->quoteName('id') . ' = ' . $id)
            ->where($db->quoteName('user_id') . ' = ' . (int) $user->id);

        $db->setQuery($q);
        try {
            $db->execute();
            $app->enqueueMessage('Saved setting deleted.', 'message');
            $app->redirect(\Joomla\CMS\Uri\Uri::getInstance()->toString());
        } catch (Exception $e) {
            $app->enqueueMessage('Error deleting saved setting: ' . $e->getMessage(), 'error');
        }
    }

    // Save prediction run as "Best Settings" template (upsert by lottery+name)
    if ($app->input->post->get('save_best_settings', '', 'STRING') !== '') {
        $bsSource    = $app->input->post->getString('bs_source', '');
        $bsLotteryId = $app->input->post->getInt('bs_lottery_id', 0);
        $bsRunId     = $app->input->post->getInt('bs_run_id', 0);
        $bsName      = trim($app->input->post->getString('bs_name', ''));

        if ($bsRunId > 0 && $bsLotteryId > 0 && $bsSource !== '') {
            // Load the original run to read its settings
            $rq = $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName('#__user_saved_numbers'))
                ->where($db->quoteName('id')      . ' = ' . (int)$bsRunId)
                ->where($db->quoteName('user_id') . ' = ' . (int)$user->id);
            $db->setQuery($rq);
            $runRow = $db->loadAssoc();

            if ($runRow) {
                $allKeys = [
                    'epochs','batch_size','dropout_rate','learning_rate',
                    'activation_function','hidden_layers','recency_decay',
                    'skai_window_size','skip_window','draws_used','tuned_window',
                    'sampling_temperature','diversity_penalty','gap_scale',
                    'skai_run_mode','auto_tune','tune_used','best_window',
                    'skai_top_n_numbers','skai_top_n_combos',
                    'walks','burn_in','laplace_k','decay','chain_len',
                    'draws_analyzed','freq_weight','skip_weight','hist_weight',
                ];
                $bsParams = [];
                foreach ($allKeys as $k) {
                    if (isset($runRow[$k]) && $runRow[$k] !== '' && $runRow[$k] !== null) {
                        $bsParams[$k] = $runRow[$k];
                    }
                }
                if (!$bsName) {
                    $bsName = 'Best Settings';
                }
                // Delete any existing "Best Settings" template for this lottery+source
                $delQ = $db->getQuery(true)
                    ->delete($db->quoteName('#__user_saved_settings'))
                    ->where($db->quoteName('user_id')      . ' = ' . (int)$user->id)
                    ->where($db->quoteName('lottery_id')   . ' = ' . (int)$bsLotteryId)
                    ->where($db->quoteName('source')       . ' = ' . $db->quote($bsSource))
                    ->where($db->quoteName('setting_name') . ' = ' . $db->quote($bsName));
                $db->setQuery($delQ);
                try { $db->execute(); } catch (Exception $e) {}

                // Insert the new template
                if ($bsParams) {
                    $insQ = $db->getQuery(true)
                        ->insert($db->quoteName('#__user_saved_settings'))
                        ->columns(['user_id','lottery_id','source','setting_name','params'])
                        ->values(implode(',', [
                            (int)$user->id,
                            (int)$bsLotteryId,
                            $db->quote($bsSource),
                            $db->quote($bsName),
                            $db->quote(json_encode($bsParams)),
                        ]));
                    $db->setQuery($insQ);
                    try {
                        $db->execute();
                        $app->enqueueMessage('Best settings saved as "' . htmlspecialchars($bsName, ENT_QUOTES) . '".', 'message');
                    } catch (Exception $e) {
                        $app->enqueueMessage('Failed to save best settings: ' . $e->getMessage(), 'error');
                    }
                }
            }
        }
        $app->redirect(\Joomla\CMS\Uri\Uri::getInstance()->toString());
        exit;
    }

    // Save Best Settings + Notes bundle into #__skai_best_settings (upsert per user+lottery)
    if ($app->input->post->get('save_best_bundle', '', 'STRING') !== '') {
        $bbLotteryId = $app->input->post->getInt('bb_lottery_id', 0);
        $bbRunId     = $app->input->post->getInt('bb_run_id', 0);
        $bbSource    = $app->input->post->getString('bb_source', '');
        $bbNotes     = (string)$app->input->post->get('bb_notes', '', 'RAW');
        $bbNotes     = mb_substr(strip_tags($bbNotes), 0, 2000); // max 2000 chars, no HTML

        if ($bbLotteryId > 0) {
            // Attempt to load params from the winning run
            $bbParams   = [];
            $bbSummary  = '';
            if ($bbRunId > 0) {
                $rq = $db->getQuery(true)
                    ->select('*')
                    ->from($db->quoteName('#__user_saved_numbers'))
                    ->where($db->quoteName('id')      . ' = ' . (int)$bbRunId)
                    ->where($db->quoteName('user_id') . ' = ' . (int)$user->id);
                $db->setQuery($rq);
                $bbRow = $db->loadAssoc();
                if ($bbRow) {
                    $__mlInline = [
                        'skip_hit'        => 'Skip & Hit',
                        'ai_prediction'   => 'AI',
                        'mcmc_prediction' => 'MCMC',
                        'skai_prediction' => 'SKAI',
                        'heatmap'         => 'Frequency Map',
                    ];
                    $bbSummary = buildBestSettingsSummary($bbRow, $__mlInline);
                    $allKeys = [
                        'epochs','batch_size','dropout_rate','learning_rate',
                        'activation_function','hidden_layers','recency_decay',
                        'skai_window_size','skip_window','draws_used','tuned_window',
                        'sampling_temperature','diversity_penalty','gap_scale',
                        'skai_run_mode','auto_tune','tune_used','best_window',
                        'skai_top_n_numbers','skai_top_n_combos',
                        'walks','burn_in','laplace_k','decay','chain_len',
                        'draws_analyzed','freq_weight','skip_weight','hist_weight',
                        'skai_blend_skip_pct','skai_blend_ai_pct',
                    ];
                    foreach ($allKeys as $k) {
                        if (isset($bbRow[$k]) && $bbRow[$k] !== '' && $bbRow[$k] !== null) {
                            $bbParams[$k] = $bbRow[$k];
                        }
                    }
                }
            }
            // Use summary passed from form as fallback (already rendered server-side)
            if ($bbSummary === '') {
                $bbSummary = mb_substr(strip_tags($app->input->post->getString('bb_summary', '')), 0, 500);
            }

            // Check if row exists
            $tbl = '#__skai_best_settings';
            $cq = $db->getQuery(true)
                ->select('id')
                ->from($db->quoteName($tbl))
                ->where($db->quoteName('user_id')    . ' = ' . (int)$user->id)
                ->where($db->quoteName('lottery_id') . ' = ' . (int)$bbLotteryId);
            $db->setQuery($cq);
            $existingId = (int)$db->loadResult();

            try {
                if ($existingId > 0) {
                    // UPDATE
                    $uq = $db->getQuery(true)
                        ->update($db->quoteName($tbl))
                        ->set($db->quoteName('settings_json')    . ' = ' . $db->quote(json_encode($bbParams)))
                        ->set($db->quoteName('settings_summary') . ' = ' . $db->quote($bbSummary))
                        ->set($db->quoteName('notes')            . ' = ' . $db->quote($bbNotes))
                        ->set($db->quoteName('updated_at')       . ' = NOW()')
                        ->where($db->quoteName('id')             . ' = ' . $existingId);
                    $db->setQuery($uq);
                    $db->execute();
                    $app->enqueueMessage('Best settings updated.', 'message');
                } else {
                    // INSERT
                    $iq = $db->getQuery(true)
                        ->insert($db->quoteName($tbl))
                        ->columns($db->quoteName(['user_id','lottery_id','settings_json','settings_summary','notes']))
                        ->values(implode(',', [
                            (int)$user->id,
                            (int)$bbLotteryId,
                            $db->quote(json_encode($bbParams)),
                            $db->quote($bbSummary),
                            $db->quote($bbNotes),
                        ]));
                    $db->setQuery($iq);
                    $db->execute();
                    $app->enqueueMessage('Best settings saved.', 'message');
                }
            } catch (Exception $e) {
                $app->enqueueMessage('Could not save: ' . $e->getMessage(), 'error');
            }
        }
        // Redirect back to the same page anchor
        $uri = \Joomla\CMS\Uri\Uri::getInstance();
        $app->redirect($uri->toString() . '#best-opp-' . (int)$bbLotteryId);
        exit;
    }

    // Save prediction settings as a named template (hardened: use Joomla input filtering)
    if ($app->input->post->get('save_setting_template', '', 'STRING') !== '') {
        $settingName = trim($app->input->post->getString('setting_name', ''));
        $source      = $app->input->post->getString('source', '');
        $lotteryId   = $app->input->post->getInt('lottery_id', 0);
        $params      = [];

        // Define expected params based on source
        $keys = match ($source) {
            'ai_prediction'   => [
                'epochs', 'batch_size', 'dropout_rate',
                'learning_rate', 'activation_function',
                'hidden_layers', 'recency_decay',
            ],
            'skai_prediction' => [
                // Core neural hyperparameters
                'epochs', 'batch_size', 'dropout_rate',
                'learning_rate', 'activation_function',
                'hidden_layers', 'recency_decay',
                // Windows
                'skai_window_size', 'skip_window', 'draws_used', 'tuned_window',
                // Behavior & sampling
                'sampling_temperature', 'diversity_penalty', 'gap_scale',
                // Mode / automation
                'skai_run_mode', 'auto_tune', 'tune_used', 'best_window',
                // Output size
                'skai_top_n_numbers', 'skai_top_n_combos',
            ],
            'mcmc_prediction' => ['walks', 'burn_in', 'laplace_k', 'decay', 'chain_len'],
            'skip_hit'        => ['draws_analyzed', 'freq_weight', 'skip_weight', 'hist_weight'],
            default           => []
        };

        foreach ($keys as $k) {
            $val = $app->input->post->get($k, null, 'STRING'); // keep as STRING to avoid behavior changes
            if ($val !== null && $val !== '') {
                $params[$k] = $val;
            }
        }


        if ($settingName && $source && $lotteryId && $params) {
            $q = $db->getQuery(true)
                ->insert($db->quoteName('#__user_saved_settings'))
                ->columns(['user_id', 'lottery_id', 'source', 'setting_name', 'params'])
                ->values(implode(',', [
                    (int) $user->id,
                    $lotteryId,
                    $db->quote($source),
                    $db->quote($settingName),
                    $db->quote(json_encode($params))
                ]));

            $db->setQuery($q);
            try {
                $db->execute();
                $app->enqueueMessage('Settings saved.', 'message');
            } catch (Exception $e) {
                $app->enqueueMessage('Failed to save: ' . $e->getMessage(), 'error');
            }
        }
    }
}

// --------------------------------------------------
// Echo your Sorcerer module
// --------------------------------------------------
echo '{loadmoduleid 126}';
?>

[[div class="skai-section skai-section--tight skai-first-section"]]

[[div class="skai-hub-hero"]]
  [[div class="skai-hub-hero__inner"]]
    [[div class="skai-hub-hero__eyebrow"]]Analysis Hub[[/div]]
    [[h1 class="skai-hub-hero__title"]]What Matters Today[[/h1]]
    [[p class="skai-hub-hero__subtitle"]]
      Review your saved analyses, see where methods agree, and choose settings with clarity.
      Results are probabilistic -- never guaranteed.
    [[/p]]
    [[div class="skai-hub-hero__meta"]]
      [[span class="skai-hub-hero__welcome"]]
        <?php echo 'Welcome back, ' . htmlspecialchars($user->name, ENT_QUOTES) . '.'; ?>
      [[/span]]
      [[span class="skai-hub-hero__tag"]]Member Dashboard[[/span]]
      [[a href="/why-lottoexpert-is-different"
          class="skai-hub-hero__link"
          target="_blank"
          rel="noopener noreferrer"]]
        Why data-driven analysis matters
      [[/a]]
    [[/div]]
  [[/div]]
[[/div]]

[[div id="skai-lottery-navigator" class="skai-nav-pills-wrap" aria-label="Lottery sections" role="navigation"]]
  [[div class="skai-nav-pills" id="skai-nav-pills-inner"]]
    <!-- JS populates lottery navigator pills here -->
  [[/div]]
[[/div]]

<!-- [SKAI-REF-01] Best Settings Snapshot Panel -->
[[div id="skai-global-snapshot" class="skai-global-snapshot" aria-label="Best settings snapshot" role="region"]]
  [[div class="skai-bss-header"]]
    [[h2 class="skai-bss-title"]]Best Settings Snapshot[[/h2]]
    [[p class="skai-bss-subtitle"]]Best-performing settings per lottery (Highest Average Hits).[[/p]]
  [[/div]]
  [[div class="skai-snapshot-grid"]]
    [[div class="skai-snapshot-metric" id="snap-lotteries"]]
      [[div class="skai-snapshot-label"]]Active Lotteries[[/div]]
      [[div class="skai-snapshot-value" id="snap-lotteries-val"]]--[[/div]]
    [[/div]]
    [[div class="skai-snapshot-metric" id="snap-scored-lotteries"]]
      [[div class="skai-snapshot-label"]]With Scored History[[/div]]
      [[div class="skai-snapshot-value" id="snap-scored-lotteries-val"]]--[[/div]]
    [[/div]]
    [[div class="skai-snapshot-metric" id="snap-total-scored"]]
      [[div class="skai-snapshot-label"]]Total Scored Runs[[/div]]
      [[div class="skai-snapshot-value" id="snap-total-scored-val"]]--[[/div]]
    [[/div]]
  [[/div]]
[[div id="skai-best-settings-list" class="skai-best-settings-list" aria-label="Best settings per lottery"]]
<?php
$__snapCardCount = 0;

foreach ($bestOpps as $__sid => $__sopp) {
    $__bav = $__sopp['best_avg_hits'] ?? null;
    if (!$__bav || empty($__bav['scored_runs']) || (int)$__bav['scored_runs'] <= 0) {
        continue;
    }

    $__snapCardCount++;

    $__lotName = (string)($__sopp['lottery_name'] ?? '');
    $__stateName = (string)($__sopp['state_name'] ?? '');
    $__logo = buildLotteryLogoPath($__stateName, $__lotName);

    $__bestRunId = (int)($__bav['run_id'] ?? 0);
    $__avgHits = (string)($__bav['avg_hits'] ?? '0');
    $__totalScored = (int)($__bav['scored_runs'] ?? 0);
    $__lastScored = (string)($__bav['last_scored_date'] ?? '');
    $__methodName = (string)($__bav['display_name'] ?? '');

    $__bestSingle = 0;
    $__series = [];
    $__seriesMax = 0;

    $___runs = $__sopp['all_scored_runs'] ?? [];
    if (!is_array($___runs)) $___runs = [];

    $___tmp = [];
    foreach ($___runs as $r) {
        if ((int)($r['run_id'] ?? 0) !== $__bestRunId) continue;
        $d = (string)($r['draw_date'] ?? '');
        $hm = (int)($r['hits'] ?? 0);
        $he = (int)($r['extra_hits'] ?? 0);
        $tot = $hm + $he;
        if ($tot > $__bestSingle) $__bestSingle = $tot;
        $___tmp[] = ['d' => $d, 'v' => $tot];
    }

    usort($___tmp, function($a, $b){
        $ad = (string)($a['d'] ?? '');
        $bd = (string)($b['d'] ?? '');
        if ($ad === $bd) return 0;
        return ($ad < $bd) ? -1 : 1;
    });

    $___tmpCount = count($___tmp);
    $___start = ($___tmpCount > 10) ? ($___tmpCount - 10) : 0;
    for ($i = $___start; $i < $___tmpCount; $i++) {
        $val = (int)($___tmp[$i]['v'] ?? 0);
        $__series[] = $val;
        if ($val > $__seriesMax) $__seriesMax = $val;
    }

    $__canChart = (count($__series) >= 2 && $__seriesMax > 0);
?>
  [[div class="skai-bss-card"]]
    [[div class="skai-bss-card__head"]]
      <?php if (!empty($__logo['path'])): ?>
        <?php if (!empty($__logo['exists'])): ?>
          [[img class="skai-bss-logo"
               src="<?php echo htmlspecialchars($__logo['path'], ENT_QUOTES); ?>"
               alt="<?php echo htmlspecialchars($__logo['alt'], ENT_QUOTES); ?>"
               loading="lazy" width="72" height="36"]]
        <?php else: ?>
          [[div class="skai-bss-logo skai-bss-logo--fallback" aria-label="<?php echo htmlspecialchars($__logo['alt'], ENT_QUOTES); ?>"]]
            <?php
              $__pts = preg_split('/\s+/', $__lotName);
              $__ini = '';
              foreach (($__pts ?: []) as $__pt) {
                  $__pt = (string)$__pt;
                  if ($__pt !== '') { $__ini .= strtoupper(substr($__pt, 0, 1)); if (strlen($__ini) >= 2) break; }
              }
              echo htmlspecialchars($__ini ?: 'LE', ENT_QUOTES);
            ?>
          [[/div]]
        <?php endif; ?>
      <?php endif; ?>

      [[div class="skai-bss-titlewrap"]]
        [[div class="skai-bss-lotteryname"]]<?php echo htmlspecialchars($__lotName, ENT_QUOTES); ?>[[/div]]
        [[div class="skai-bss-bestmethod"]]<?php echo htmlspecialchars($__methodName, ENT_QUOTES); ?>[[/div]]
      [[/div]]
    [[/div]]

    [[div class="skai-bss-stats"]]
      [[div class="skai-bss-stat"]]
        [[div class="skai-bss-stat__label"]]Total scored runs[[/div]]
        [[div class="skai-bss-stat__value"]]<?php echo (int)$__totalScored; ?>[[/div]]
      [[/div]]
      [[div class="skai-bss-stat"]]
        [[div class="skai-bss-stat__label"]]Average hits[[/div]]
        [[div class="skai-bss-stat__value"]]<?php echo htmlspecialchars((string)$__avgHits, ENT_QUOTES); ?>[[/div]]
      [[/div]]
      [[div class="skai-bss-stat"]]
        [[div class="skai-bss-stat__label"]]Best single-run hits[[/div]]
        [[div class="skai-bss-stat__value"]]<?php echo (int)$__bestSingle; ?>[[/div]]
      [[/div]]
      [[div class="skai-bss-stat"]]
        [[div class="skai-bss-stat__label"]]Last scored draw date[[/div]]
        [[div class="skai-bss-stat__value"]]<?php echo htmlspecialchars((string)$__lastScored, ENT_QUOTES); ?>[[/div]]
      [[/div]]
    [[/div]]

    <?php if ($__canChart): ?>
      [[div class="skai-bss-chart" aria-label="Recent hit trend"]]
        <?php
          for ($i = 0; $i < count($__series); $i++) {
              $v = (int)$__series[$i];
              $h = (int)round(($v / $__seriesMax) * 100);
              if ($h < 5) $h = 5;
              if ($h > 100) $h = 100;
              echo '[[span class="skai-bss-bar" style="height:' . $h . '%" title="' . $v . '"]][[/span]]';
          }
        ?>
      [[/div]]
    <?php endif; ?>
  [[/div]]
<?php } ?>
[[/div]][[/div]]

[[style]]
/* [SKAI-REF-01] Best Settings Snapshot */
.skai-global-snapshot {
  background: linear-gradient(135deg, #0A1A33 0%, #162d52 100%);
  border-radius: 12px;
  padding: 14px 18px;
  margin: 0 0 14px 0;
  box-shadow: 0 4px 14px rgba(10,26,51,0.18);
  color: #ffffff;
}
.skai-bss-header { margin-bottom: 10px; }
.skai-bss-title {
  font-size: 1rem;
  font-weight: 800;
  color: #ffffff;
  margin: 0 0 2px 0;
  line-height: 1.2;
}
.skai-bss-subtitle {
  font-size: 0.75rem;
  color: rgba(255,255,255,0.60);
  margin: 0 0 10px 0;
}
.skai-snapshot-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 10px 16px;
}
@media (max-width: 640px) {
  .skai-snapshot-grid { grid-template-columns: repeat(2, 1fr); }
}
.skai-snapshot-metric {
  background: rgba(255,255,255,0.07);
  border: 1px solid rgba(255,255,255,0.12);
  border-radius: 10px;
  padding: 10px 12px;
}
.skai-snapshot-label {
  font-size: 0.7rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: rgba(255,255,255,0.60);
  margin-bottom: 4px;
}
.skai-snapshot-value {
  font-size: 1.05rem;
  font-weight: 800;
  color: #ffffff;
  line-height: 1.2;
}
.skai-snapshot-value--sm { font-size: 0.88rem; }
/* Per-lottery best settings list - card layout */
.skai-best-settings-list{
  margin-top: 12px;
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

.skai-bss-card{
  background: rgba(255,255,255,0.08);
  border: 1px solid rgba(255,255,255,0.14);
  border-radius: 12px;
  padding: 10px 12px;
  width: 320px;
  max-width: 100%;
  box-shadow: 0 4px 14px rgba(10,26,51,0.10);
}

.skai-bss-card__head{
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 8px;
}

.skai-bss-logo{
  border-radius: 8px;
  background: rgba(255,255,255,0.10);
  border: 1px solid rgba(255,255,255,0.14);
  object-fit: contain;
}

.skai-bss-logo--fallback{
  width: 72px;
  height: 36px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 800;
  color: #ffffff;
}

.skai-bss-titlewrap{ min-width: 0; }
.skai-bss-lotteryname{
  font-size: 0.92rem;
  font-weight: 800;
  color: #ffffff;
  line-height: 1.2;
}
.skai-bss-bestmethod{
  font-size: 0.78rem;
  color: rgba(255,255,255,0.75);
  margin-top: 2px;
  line-height: 1.25;
}

.skai-bss-stats{
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 6px;
}

.skai-bss-stat{
  flex: 1 1 140px;
  background: rgba(255,255,255,0.06);
  border: 1px solid rgba(255,255,255,0.10);
  border-radius: 10px;
  padding: 8px 9px;
}

.skai-bss-stat__label{
  font-size: 0.68rem;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: rgba(255,255,255,0.65);
}
.skai-bss-stat__value{
  margin-top: 3px;
  font-size: 0.92rem;
  font-weight: 800;
  color: #ffffff;
}

.skai-bss-chart{
  margin-top: 10px;
  height: 34px;
  display: flex;
  align-items: flex-end;
  gap: 3px;
}

.skai-bss-bar{
  width: 8px;
  display: inline-block;
  border-radius: 4px;
  background: rgba(255,255,255,0.85);
  border: 1px solid rgba(255,255,255,0.18);
}
/* [SKAI-REF-05] Focus Mode (kept for collapse helper) */
.skai-focus-on .lottery-group:not(.skai-focus-winner) {
  opacity: 0.4;
  filter: grayscale(0.3);
  transition: opacity 0.25s ease, filter 0.25s ease;
}
.skai-focus-on .lottery-group.skai-focus-winner {
  box-shadow: 0 0 0 3px #1C66FF, 0 8px 24px rgba(28,102,255,0.22);
  transition: box-shadow 0.25s ease;
}

/* [SKAI-REF-06] Advanced Tools Details */
.skai-adv-tools-details {
  display: inline-block;
  position: relative;
}
.skai-adv-tools-summary {
  cursor: pointer;
  font-size: 0.78rem;
  font-weight: 700;
  color: #6b7280;
  padding: 0.2rem 0.5rem;
  border: 1px solid rgba(10,26,51,0.12);
  border-radius: 6px;
  list-style: none;
  user-select: none;
}
.skai-adv-tools-summary::-webkit-details-marker { display: none; }
.skai-adv-tools-body {
  position: absolute;
  z-index: 100;
  background: #ffffff;
  border: 1px solid rgba(10,26,51,0.14);
  border-radius: 10px;
  padding: 10px 14px;
  box-shadow: 0 8px 24px rgba(10,26,51,0.12);
  min-width: 160px;
  margin-top: 4px;
}

/* -- Best Opportunities Panel -- */
.skai-best-opps {
  margin: 0 0 1.5rem 0;
}
.skai-best-opps__heading {
  font-size: 1.15rem;
  font-weight: 800;
  color: #0A1A33;
  margin: 0 0 0.2rem 0;
}
.skai-best-opps__sub {
  font-size: 0.85rem;
  color: #475569;
  margin: 0 0 1rem 0;
}
.skai-best-opps__grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
  gap: 1rem;
}
.skai-opp-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  padding: 1rem 1.1rem;
  box-shadow: 0 1px 4px rgba(10,26,51,0.07);
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}
.skai-opp-card__badge {
  display: inline-block;
  font-size: 0.68rem;
  font-weight: 700;
  letter-spacing: 0.07em;
  text-transform: uppercase;
  padding: 0.2rem 0.55rem;
  border-radius: 999px;
  margin-bottom: 0.15rem;
}
.skai-opp-card__badge--agree  { background: #e0f2fe; color: #0369a1; }
.skai-opp-card__badge--rank   { background: #fef3c7; color: #92400e; }
.skai-opp-card__metric {
  font-size: 1.5rem;
  font-weight: 800;
  color: #0A1A33;
  line-height: 1.1;
}
.skai-opp-card__label {
  font-size: 0.8rem;
  color: #64748b;
  margin-top: -0.15rem;
}
.skai-opp-card__def {
  font-size: 0.78rem;
  color: #64748b;
  border-left: 3px solid #e2e8f0;
  padding-left: 0.5rem;
  line-height: 1.4;
}
.skai-opp-card__numbers {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  margin-top: 0.1rem;
}
.skai-opp-card__num {
  background: #f1f5f9;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  font-size: 0.78rem;
  font-weight: 700;
  padding: 2px 7px;
  color: #0A1A33;
}
.skai-opp-card__methods {
  font-size: 0.75rem;
  color: #475569;
}
.skai-opp-card__why {
  font-size: 0.78rem;
  color: #475569;
  font-style: italic;
  margin-top: 0.1rem;
}
/* [SKAI-SINGLE-RUN-02] Single-run winner label */
.skai-opp-card__run-label {
  font-size: 0.72rem;
  color: #64748b;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: 2px 8px;
  display: inline-block;
  margin-top: 0.3rem;
}
.skai-opp-card__see-runs {
  font-size: 0.72rem;
  color: #1C66FF;
  background: none;
  border: none;
  cursor: pointer;
  padding: 0;
  text-decoration: underline;
  margin-top: 0.2rem;
  display: inline-block;
}
.skai-opp-card__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 0.35rem;
}
.skai-opp-card__cta {
  font-size: 0.78rem;
  font-weight: 700;
  padding: 0.28rem 0.65rem;
  border-radius: 8px;
  border: none;
  cursor: pointer;
  text-decoration: none;
  display: inline-block;
  line-height: 1.4;
}
.skai-opp-card__cta--primary {
  background: #1C66FF;
  color: #ffffff;
}
.skai-opp-card__cta--primary:hover { background: #1558e0; }
.skai-opp-card__cta--secondary {
  background: #f1f5f9;
  color: #0A1A33;
  border: 1px solid #e2e8f0;
}
.skai-opp-card__cta--secondary:hover { background: #e2e8f0; }
.skai-opp-card--empty {
  background: #f8fafc;
  border-style: dashed;
}
.skai-opp-card--empty .skai-opp-card__metric {
  font-size: 0.9rem;
  color: #94a3b8;
  font-weight: 500;
}
.skai-opp-banner {
  font-size: 0.78rem;
  color: #475569;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 0.4rem 0.75rem;
  margin-top: 0.5rem;
}
.skai-opp-banner--warn { background: #fffbeb; border-color: #fde68a; color: #92400e; }

/* -- Best Opportunities: Settings Summary -- */
.skai-opp-settings-summary {
  font-size: 0.75rem;
  color: #0A1A33;
  background: #f0f4ff;
  border: 1px solid #c7d7ff;
  border-radius: 7px;
  padding: 0.3rem 0.6rem;
  margin: 0.35rem 0 0.1rem;
  line-height: 1.5;
}
.skai-opp-settings-summary__label {
  font-weight: 700;
  color: #1C66FF;
  margin-right: 0.3em;
}

/* -- Best Opportunities: Notes -- */
.skai-opp-notes-wrap {
  margin-top: 0.55rem;
  border-top: 1px solid #f1f5f9;
  padding-top: 0.5rem;
}
.skai-opp-notes-wrap__label {
  display: block;
  font-size: 0.7rem;
  font-weight: 700;
  color: #475569;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  margin-bottom: 0.25rem;
}
.skai-opp-notes-wrap__textarea {
  width: 100%;
  min-height: 60px;
  max-height: 120px;
  font-size: 0.78rem;
  line-height: 1.45;
  color: #0A1A33;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 7px;
  padding: 0.35rem 0.55rem;
  resize: vertical;
  box-sizing: border-box;
  display: block;
  margin-bottom: 0.45rem;
}
.skai-opp-notes-wrap__textarea:focus {
  outline: 2px solid #1C66FF;
  border-color: #1C66FF;
  background: #fff;
}
.skai-opp-card__cta--save {
  background: #1C66FF;
  color: #ffffff;
  font-size: 0.78rem;
  font-weight: 700;
  padding: 0.3rem 0.8rem;
  border-radius: 8px;
  border: none;
  cursor: pointer;
}
.skai-opp-card__cta--save:hover { background: #1558e0; }
.skai-opp-card__cta--save.is-saved { background: #059669; }
.skai-opp-card__cta--save.is-saved:hover { background: #047857; }

/* -- Rank Accuracy Insight block inside Top Hits card -- */
.skai-opp-rank-insight {
  margin-top: 0.85rem;
  padding: 0.75rem 0.9rem;
  background: #f7fbff;
  border: 1px solid #dfe6f2;
  border-radius: 8px;
  font-size: 0.82rem;
  line-height: 1.45;
  color: #2c3e50;
}
.skai-opp-rank-insight__title {
  font-weight: 700;
  font-size: 0.85rem;
  color: #1f2a3a;
  margin-bottom: 0.3rem;
  letter-spacing: 0.01em;
}
.skai-opp-rank-insight__best {
  margin-bottom: 0.25rem;
}
.skai-opp-rank-insight__advice {
  color: #34495e;
  margin-bottom: 0.3rem;
}
.skai-opp-rank-insight__methods {
  color: #4a5568;
  font-size: 0.78rem;
}

/* -- Cumulative Runs Report -- */
.skai-crr-wrap { margin: 0 0 1.25rem; }
.skai-crr-heading {
  font-size: 1.1rem; font-weight: 800; color: #0A1A33; margin: 0 0 0.75rem;
}
.skai-crr-card {
  background: #fff; border: 1px solid #e2e8f0; border-radius: 12px;
  padding: 1rem 1.1rem 0.9rem; margin-bottom: 1.25rem;
  box-shadow: 0 1px 4px rgba(10,26,51,0.06);
}
.skai-crr-card__header {
  display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.75rem;
}
.skai-crr-card__name { font-size: 0.95rem; font-weight: 700; color: #0A1A33; }
.skai-crr-narrative {
  font-size: 0.82rem; line-height: 1.5; color: #475569;
  background: #f8fafc; border-left: 3px solid #1C66FF;
  padding: 0.55rem 0.75rem; border-radius: 0 6px 6px 0; margin-bottom: 0.85rem;
}
.skai-crr-best-summary {
  font-size: 0.85rem; font-weight: 600; color: #0A1A33;
  background: #eff6ff; border: 1px solid #bfdbfe;
  border-radius: 6px; padding: 0.5rem 0.75rem; margin-bottom: 0.75rem;
}
.skai-crr-filters {
  display: flex; flex-wrap: wrap; gap: 0.35rem;
  margin-bottom: 0.75rem; align-items: center;
}
.skai-crr-filters__label {
  font-size: 0.75rem; font-weight: 700; color: #64748b; margin-right: 0.25rem;
}
.skai-crr-filter-chip {
  display: inline-flex; align-items: center;
  padding: 0.2rem 0.65rem; border-radius: 999px;
  font-size: 0.75rem; font-weight: 600;
  border: 1.5px solid #cbd5e1; cursor: pointer;
  user-select: none; background: #f1f5f9; color: #0A1A33;
  transition: background 0.15s, color 0.15s;
}
.skai-crr-filter-chip[aria-pressed="true"] {
  background: #0A1A33; color: #fff; border-color: #0A1A33;
}
.skai-crr-table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 0.82rem; }
.skai-crr-table th {
  text-align: left; font-size: 0.7rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: 0.05em; color: #64748b;
  padding: 0.35rem 0.55rem; border-bottom: 2px solid #e2e8f0;
}
.skai-crr-table td { padding: 0.5rem 0.55rem; vertical-align: middle; border-bottom: 1px solid #f1f5f9; }
.skai-crr-row--best { background: #eff6ff; }
.skai-crr-row--best td:first-child { border-left: 3px solid #1C66FF; padding-left: 0.3rem; }
.skai-crr-badge-best {
  display: inline-block; background: #1C66FF; color: #fff;
  font-size: 0.6rem; font-weight: 700; letter-spacing: 0.06em;
  text-transform: uppercase; border-radius: 3px;
  padding: 1px 5px; vertical-align: middle; margin-left: 4px;
}
.skai-crr-src-chip {
  display: inline-block; padding: 0.15rem 0.5rem;
  border-radius: 4px; font-size: 0.72rem; font-weight: 700; white-space: nowrap;
}
.skai-crr-src-chip--skai    { background: #cffafe; color: #0e7490; }
.skai-crr-src-chip--ai      { background: #dcfce7; color: #166534; }
.skai-crr-src-chip--skip    { background: #dbeafe; color: #1d4ed8; }
.skai-crr-src-chip--mcmc    { background: #f3e8ff; color: #7e22ce; }
.skai-crr-src-chip--heatmap { background: #ffedd5; color: #9a3412; }
.skai-crr-src-chip--other   { background: #f1f5f9; color: #475569; }

.skai-crr-empty {
  padding: 1.5rem; text-align: center; color: #64748b;
  font-size: 0.85rem; background: #f8fafc;
  border-radius: 8px; border: 1px dashed #cbd5e1;
}
.skai-crr-hits-main  { font-weight: 700; color: #0A1A33; }
.skai-crr-hits-extra { color: #059669; font-weight: 600; }
.skai-crr-hits-zero  { color: #94a3b8; }
@media (max-width: 600px) {
  .skai-crr-table thead { display: none; }
  .skai-crr-table tbody tr {
    display: block; border: 1px solid #e2e8f0; border-radius: 8px;
    margin-bottom: 0.6rem; padding: 0.5rem 0.65rem;
  }
  .skai-crr-table td {
    display: flex; align-items: flex-start; gap: 0.4rem;
    padding: 0.2rem 0; border: none; font-size: 0.82rem;
  }
  .skai-crr-table td::before {
    content: attr(data-label); font-weight: 700;
    min-width: 80px; color: #64748b; font-size: 0.72rem; flex-shrink: 0;
  }
  .skai-crr-row--best { border-left: 3px solid #1C66FF !important; }
  .skai-crr-row--best td:first-child { border-left: none; padding-left: 0; }
}

/* -- Trends & Evidence Section -- */
.skai-trends {
  display: none;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  padding: 1rem 1.1rem 0.9rem;
  margin: 0 0 1.25rem 0;
  box-shadow: 0 1px 4px rgba(10,26,51,0.06);
}
.skai-trends__heading {
  font-size: 1rem;
  font-weight: 800;
  color: #0A1A33;
  margin: 0 0 0.5rem 0;
}
.skai-trends__confidence {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  flex-wrap: wrap;
  margin-bottom: 0.25rem;
}
.skai-trends__conf-label {
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.07em;
  color: #64748b;
}
.skai-trends__conf-value {
  font-size: 0.9rem;
  font-weight: 800;
  padding: 0.1rem 0.55rem;
  border-radius: 999px;
}
.skai-trends__conf-low      { background: #fee2e2; color: #991b1b; }
.skai-trends__conf-building { background: #fef3c7; color: #92400e; }
.skai-trends__conf-strong   { background: #d1fae5; color: #065f46; }
.skai-trends__conf-evidence {
  font-size: 0.72rem;
  color: #94a3b8;
}
.skai-trends__guidance {
  font-size: 0.78rem;
  color: #475569;
  margin: 0 0 0.75rem 0;
  font-style: italic;
}
.skai-trends__chart-wrap {
  margin-top: 0.5rem;
}
.skai-trends__chart-title {
  font-size: 0.78rem;
  font-weight: 700;
  color: #0A1A33;
  margin-bottom: 0.1rem;
}
.skai-trends__chart-sub {
  font-size: 0.7rem;
  color: #64748b;
  margin-bottom: 0.4rem;
}
.skai-trends__canvas {
  display: block;
  width: 100%;
  max-width: 600px;
  height: 220px;
  border-radius: 8px;
  background: #f8fafc;
}
.skai-trends__chart-fallback {
  font-size: 0.8rem;
  color: #475569;
}
.skai-trends__fallback-row {
  display: flex;
  gap: 1rem;
  padding: 0.25rem 0;
  border-bottom: 1px solid #f1f5f9;
}
.skai-trends__fallback-name { font-weight: 700; min-width: 120px; }

/* Lottery header mini-summary */
.skai-lottery-summary {
  display: flex;
  flex-wrap: wrap;
  gap: 6px 10px;
  font-size: 0.75rem;
  color: #475569;
  margin-top: 4px;
}
.skai-lottery-summary__chip {
  background: #f1f5f9;
  border-radius: 6px;
  padding: 2px 8px;
  white-space: nowrap;
}
.skai-lottery-summary__chip b { color: #0A1A33; }

/* Top�Hits hero styling (agency-grade upgrade)
   We elevate the Most�Hits metric to hero status with clear hierarchy.
   The badge uses the primary accent colour; the number is large and bold;
   supporting lines use muted colours for readability. */
.skai-opp-hero {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  margin-bottom: 0.75rem;
}
/* Badge indicating this card shows Top�Hits */
.skai-opp-hero-badge {
  font-size: 0.65rem;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  background-color: rgba(28, 102, 255, 0.08);
  color: #1C66FF;
  border-radius: 3px;
  padding: 2px 6px;
  margin-bottom: 0.3rem;
}
.skai-opp-hero-number {
  font-size: 2.8rem;
  font-weight: 700;
  color: #1C66FF;
  line-height: 1;
}
.skai-opp-hero-number .skai-opp-hero-extra {
  font-size: 1.5rem;
  font-weight: 600;
  color: #0A1A33;
  margin-left: 0.3rem;
}
.skai-opp-hero-label {
  font-size: 0.8rem;
  color: #475569;
  margin-top: 0.15rem;
}
.skai-opp-hero-sub {
  font-size: 0.75rem;
  color: #64748B;
  margin-top: 0.1rem;
}
.skai-opp-hero-explainer {
  font-size: 0.72rem;
  color: #64748B;
  margin-top: 0.2rem;
}


[[/style]]

[[style]]
/* Base SKAI card - shared by .skai-card elements (info-card, legend-card, etc.) */
.skai-card {
  background: var(--skai-surface, #ffffff);
  border: 1px solid var(--skai-border-subtle, #e2e8f0);
  border-radius: 12px;
  box-shadow: var(--skai-shadow-soft, 0 1px 3px rgba(10,26,51,0.06));
  padding: 1.25rem 1.5rem;
  margin-bottom: 1.25rem;
}
.skai-card__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding-bottom: 0.75rem;
  border-bottom: 1px solid var(--skai-border-subtle, #e2e8f0);
  margin-bottom: 0.75rem;
}
.skai-card__title {
  font-size: 1rem;
  font-weight: 700;
  color: var(--skai-heading, #0A1A33);
  margin: 0;
  letter-spacing: -0.01em;
}

/* Compact variant - mapped to SKAI card */
.info-card.compact { padding: 0.75rem; }

/* Tighten top-of-page so Module 126 sits flush at top */
.skai-first-section {
  margin-top: 0 !important;
  padding-top: 0 !important;
}

/* Hide Joomla article title for this dashboard only */
.skai-page-scope ~ .page-header,
.skai-page-scope ~ .page-header h1,
.skai-page-scope ~ h1.page-header,
.skai-page-scope ~ .sp-page-title,
.skai-page-scope ~ .sp-page-title h1,
.skai-page-scope ~ .article-header,
.skai-page-scope ~ .article-header h1 {
  display: none !important;
  margin: 0 !important;
  padding: 0 !important;
}

/* If Helix adds top padding above component, tighten it */
.skai-page-scope {
  margin-top: 0 !important;
  padding-top: 0 !important;
}

  .info-card.compact .skai-card__header { padding-bottom: 0.5rem; }
  .info-card.compact .skai-card__body { font-size: 0.9rem; line-height:1.3; }
  .info-card.compact ul { margin:0; padding-left:1.2rem; }
  .info-card.compact li { margin-bottom:0.4rem; }
  @media(min-width:640px) {
    .info-card.compact .columns { display:flex; gap:2rem; }
    .info-card.compact .column { flex:1; }
  }

/* ---- SKAI Hub Hero ---- */
.skai-hub-hero {
  background: linear-gradient(135deg, #0A1A33 0%, #1C66FF 100%);
  border-radius: 14px;
  padding: 28px 32px 24px;
  margin-bottom: 16px;
  position: relative;
  overflow: hidden;
}
.skai-hub-hero::before {
  content: "";
  position: absolute;
  inset: 0;
  background: radial-gradient(circle at 10% 20%, rgba(255,255,255,0.10) 0, rgba(255,255,255,0) 50%);
  pointer-events: none;
}
.skai-hub-hero__inner {
  position: relative;
  z-index: 1;
  max-width: 680px;
}
.skai-hub-hero__eyebrow {
  font-size: 0.78rem;
  font-weight: 700;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: rgba(255,255,255,0.70);
  margin-bottom: 6px;
}
.skai-hub-hero__title {
  font-size: 1.85rem;
  font-weight: 800;
  color: #ffffff;
  letter-spacing: -0.02em;
  margin: 0 0 10px 0;
  line-height: 1.2;
}
.skai-hub-hero__subtitle {
  font-size: 0.97rem;
  color: rgba(255,255,255,0.85);
  line-height: 1.55;
  margin: 0 0 16px 0;
  max-width: 560px;
}
.skai-hub-hero__meta {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px 18px;
  font-size: 0.88rem;
}
.skai-hub-hero__welcome { color: rgba(255,255,255,0.80); }
.skai-hub-hero__tag {
  background: rgba(255,255,255,0.15);
  border: 1px solid rgba(255,255,255,0.25);
  border-radius: 999px;
  padding: 0.18rem 0.65rem;
  font-size: 0.78rem;
  font-weight: 700;
  color: #ffffff;
  letter-spacing: 0.04em;
}
.skai-hub-hero__link {
  color: rgba(255,255,255,0.75);
  text-decoration: underline;
  font-size: 0.88rem;
}
.skai-hub-hero__link:hover { color: #ffffff; }
@media (max-width: 640px) {
  .skai-hub-hero { padding: 20px 16px 18px; border-radius: 10px; }
  .skai-hub-hero__title { font-size: 1.45rem; }
}

/* ---- Lottery Navigator Pills ---- */
.skai-nav-pills-wrap {
  margin: 0 0 14px 0;
  overflow: hidden;
}
.skai-nav-pills {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  padding: 10px 12px;
  background: linear-gradient(180deg, #EFEFF5 0%, #ffffff 100%);
  border: 1px solid rgba(10,26,51,0.08);
  border-radius: 12px;
}
.skai-nav-pill {
  display: inline-flex;
  align-items: center;
  min-height: 36px;
  padding: 0.22rem 0.85rem;
  border-radius: 999px;
  font-size: 0.82rem;
  font-weight: 700;
  color: #0A1A33;
  background: #ffffff;
  border: 1px solid rgba(10,26,51,0.12);
  cursor: pointer;
  text-decoration: none;
  transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
  white-space: nowrap;
  user-select: none;
}
.skai-nav-pill:hover,
.skai-nav-pill:focus {
  background: #1C66FF;
  border-color: #1C66FF;
  color: #ffffff;
  outline: none;
}
.skai-nav-pill[aria-current="true"] {
  background: #1C66FF;
  border-color: #0A1A33;
  color: #ffffff;
  box-shadow: 0 4px 10px rgba(10,26,51,0.18);
}
@media (max-width: 640px) {
  .skai-nav-pill { min-height: 44px; padding: 0.3rem 1rem; font-size: 0.88rem; }
}

/* ---- Best Settings Card (pinned per method column) ---- */
.skai-best-card {
  background: linear-gradient(135deg, #f8fbff 0%, #eef4ff 100%);
  border: 1px solid rgba(28,102,255,0.20);
  border-left: 3px solid #1C66FF;
  border-radius: 12px;
  padding: 10px 12px 12px;
  margin-bottom: 10px;
}
.skai-best-card__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-bottom: 8px;
  flex-wrap: wrap;
}
.skai-best-card__label {
  font-size: 0.72rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.10em;
  color: #1C66FF;
}
.skai-best-card__score {
  font-size: 0.78rem;
  font-weight: 700;
  color: #20C997;
  background: rgba(32,201,151,0.12);
  border: 1px solid rgba(32,201,151,0.25);
  border-radius: 999px;
  padding: 0.12rem 0.5rem;
}
.skai-best-card__numbers {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  margin-bottom: 8px;
}
.skai-best-card__pill {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 1.8rem;
  height: 1.8rem;
  padding: 0 0.45rem;
  border-radius: 999px;
  font-size: 0.82rem;
  font-weight: 700;
  background: #ffffff;
  border: 1px solid rgba(28,102,255,0.25);
  color: #0A1A33;
}
.skai-best-card__settings {
  font-size: 0.75rem;
  color: #7F8DAA;
  line-height: 1.45;
}
.skai-best-card__settings strong { color: #0A1A33; }
.skai-best-card__ts {
  font-size: 0.72rem;
  color: #7F8DAA;
  margin-top: 4px;
}
.skai-best-card__note {
  font-size: 0.75rem;
  color: #7F8DAA;
  font-style: italic;
  margin-top: 6px;
}

/* ---- History Collapsible (method column) ---- */
.skai-history-details {
  margin-top: 8px;
  border: 1px solid rgba(10,26,51,0.08);
  border-radius: 12px;
  overflow: hidden;
}
.skai-history-summary {
  list-style: none;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 10px 14px;
  background: linear-gradient(180deg, #EFEFF5 0%, #ffffff 100%);
  font-size: 0.85rem;
  font-weight: 700;
  color: #0A1A33;
  user-select: none;
}
.skai-history-summary::-webkit-details-marker { display: none; }
.skai-history-summary::marker { display: none; }
.skai-history-body {
  padding: 10px;
  background: #ffffff;
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.skai-history-count {
  font-size: 0.75rem;
  font-weight: 600;
  color: #7F8DAA;
  background: rgba(127,141,170,0.12);
  border-radius: 999px;
  padding: 0.15rem 0.5rem;
}
.skai-show-all-wrap {
  padding: 8px 10px;
  background: #f8fafb;
  border-top: 1px dashed rgba(10,26,51,0.08);
}
.skai-show-all-btn {
  background: none;
  border: 1px solid rgba(10,26,51,0.12);
  border-radius: 8px;
  padding: 0.4rem 0.85rem;
  font-size: 0.82rem;
  font-weight: 700;
  color: #1C66FF;
  cursor: pointer;
  width: 100%;
  text-align: center;
}
.skai-show-all-btn:hover { background: rgba(28,102,255,0.06); }

[[/style]]

[[div id="info-card" class="skai-card info-card compact open"]]
  [[div class="skai-card__header" style="display:flex; align-items:center; justify-content:space-between;"]]
    [[h3 class="skai-card__title" style="margin:0; font-size:1rem; font-weight:700; color:#0A1A33;"]]
      How this works
    [[/h3]]
[[button id="info-toggle" class="info-toggle" type="button" aria-label="Hide info"
             style="background:none; border:none; font-size:1.2rem; line-height:1; cursor:pointer; color:#64748B;"]]
      x
    [[/button]]
      [[/div]]
  [[div class="skai-card__body"]]
    [[p]]
      This hub shows your saved analyses from multiple methods. When numbers appear across different methods, that is an [[strong]]agreement signal[[/strong]] -- the methods independently reached similar conclusions. Agreement signals inform your decision, but results are probabilities, not guarantees.
    [[/p]]

    [[p style="margin-top:0.75rem; font-weight:600; color:#333;"]]
      What to review:
    [[/p]]

    [[ul style="margin-top:0.25rem; margin-bottom:1rem; line-height:1.6;"]]
      [[li]][[strong]]Numbers that repeat[[/strong]] across 2+ methods -- these carry a stronger signal[[/li]]
      [[li]][[strong]]Where methods diverge[[/strong]] -- different approaches reveal different patterns[[/li]]
      [[li]][[strong]]Your saved configurations[[/strong]] -- reuse well-performing settings for upcoming draws[[/li]]
    [[/ul]]

    [[p style="font-size:0.85em; color:#555; margin-top:1rem; line-height:1.5;"]]
      Each method applies different mathematics: pattern recognition, neural networks, probability sampling. When they agree, the combined signal is more meaningful. When they differ, you see the full range of interpretations.
    [[/p]]

  [[/div]]
[[/div]]


<?php // New AI Features promo card removed intentionally - no UI rendered here. ?>


[[style]]
/* When info-card is not "open", hide its SKAI card body */
#info-card:not(.open) .skai-card__body { display: none; }

#info-toggle {
  background: none;
  border: none;
  font-size: 1.2rem;
  line-height: 1;
  color: #64748B;
  cursor: pointer;
}
/* Optional: compact switch appearance */
.skai-card__header form label input[type="checkbox"] {
  transform: scale(1.05);
}
[[/style]]

[[script]]
document.addEventListener('DOMContentLoaded', function() {
  // Render Sorcerer-style [[...]] markup to real HTML tags client-side.
  function S(str) {
    return String(str).replace(/\[\[/g, '<').replace(/\]\]/g, '>');
  }

  var card = document.getElementById('info-card');
  var btn  = document.getElementById('info-toggle');
  if (!card || !btn) return; // defensive: missing DOM nodes

  var key = 'myLottoExpertInfoOpen';

  // read persisted state safely (handles private mode / exceptions)
  var stored = null;
  try { stored = localStorage.getItem(key); } catch (e) { stored = null; }

  // default = whatever markup set (card has .open initially)
  var isOpen = (stored === null) ? card.classList.contains('open') : (stored !== 'false');

  if (!isOpen) {
    card.classList.remove('open');
    btn.setAttribute('aria-label', 'Show info');
  } else {
    btn.setAttribute('aria-label', 'Hide info');
  }

  btn.addEventListener('click', function() {
    var open = card.classList.toggle('open');
    try { localStorage.setItem(key, open ? 'true' : 'false'); } catch (e) {}
    btn.setAttribute('aria-label', open ? 'Hide info' : 'Show info');
  });
});
[[/script]]

<?php

// --------------------------------------------------
// Configuration Labels for prediction sources
// --------------------------------------------------
$sourceLabels = [
    'ai_prediction'    => 'AI',
    'skip_hit'         => 'Skip & Hit',
    'frequency'        => 'Frequency Map',
    'mcmc_prediction'  => 'MCMC',
    'heatmap'          => 'Frequency Map',
    'skai_prediction'  => 'SKAI',
];
$__methodLabels = $sourceLabels;

// Map source keys ? short codes for compact display
$iconMap = [
    'ai_prediction'   => 'AI',
    'skip_hit'        => 'S&H',
    'frequency'       => 'FM',
    'mcmc_prediction' => 'MCMC',
    'heatmap'         => 'FM',
    'skai_prediction' => 'SKAI',
];

/**
 * getLatestDrawing: Given a game ID, load the very latest draw record from that game's database table.
 *
 * @param   mixed           $gameId  Lottery game ID
 * @param   JDatabaseDriver $db      Joomla database object
 * @return  array|null              Associative array of the latest draw row, or null if not found
 */
function getLatestDrawing($gameId, $db)
{
    $configFile = JPATH_ROOT . '/lottery_skip_config.json';
    if (!file_exists($configFile)) {
        return null;
    }

    $json = json_decode(file_get_contents($configFile), true);
    if (json_last_error() !== JSON_ERROR_NONE || empty($json['lotteries'][$gameId])) {
        return null;
    }

    // Use "dbCol" as the table name
    $lotterySpec = $json['lotteries'][$gameId];
    $tbl         = $lotterySpec['dbCol'] ?? '';
    if (empty($tbl)) {
        return null;
    }

    $q = $db->getQuery(true)
        ->select('*')
        ->from($db->quoteName($db->replacePrefix($tbl)))
        ->where($db->quoteName('game_id') . ' = ' . $db->quote($gameId))
        ->order($db->quoteName('draw_date') . ' DESC')
        ->setLimit(1);

    $db->setQuery($q);
    return $db->loadAssoc();
}

/**
 * getDrawByDate: Given a game ID and a specific draw date, retrieve that exact draw row.
 *
 * @param   mixed               $gameId  Lottery game ID
 * @param   string              $date    Draw date (any strtotime-parsable string)
 * @param   JDatabaseDriver     $db      Joomla database object
 * @return  array<string,mixed>|null      Associative array of the draw row, or null if not found
 */
function getDrawByDate($gameId, $date, $db)
{
    $configFile = JPATH_ROOT . '/lottery_skip_config.json';
    if (!is_file($configFile)) {
        return null;
    }

    $json = json_decode(file_get_contents($configFile), true);
    if (json_last_error() !== JSON_ERROR_NONE
        || empty($json['lotteries'][$gameId]['dbCol'])
    ) {
        return null;
    }

    // Resolve table name from config
    $tbl = $json['lotteries'][$gameId]['dbCol'];
    if (!$tbl) {
        return null;
    }

    // Normalize incoming $date to YYYY-MM-DD; bail if invalid to avoid 1970-01-01
    $ts = strtotime((string)$date);
    if ($ts === false) {
        return null;
    }
    $dateOnly = date('Y-m-d', $ts);

    // Heuristic for subtype selection when multiple rows exist on same date.
    $dateStr = strtolower((string)$date);
    $want = '';
    if (strpos($dateStr, 'midday') !== false || strpos($dateStr, 'noon') !== false || strpos($dateStr, 'day') !== false) {
        $want = 'midday';
    } elseif (strpos($dateStr, 'evening') !== false || strpos($dateStr, 'night') !== false || strpos($dateStr, 'pm') !== false) {
        $want = 'evening';
    }

    // Build and execute query (NO limit 1; pick best candidate row)
    $query = $db->getQuery(true)
        ->select('*')
        ->from($db->quoteName($db->replacePrefix($tbl)))
        ->where($db->quoteName('game_id') . ' = ' . $db->quote($gameId))
        ->where('DATE(' . $db->quoteName('draw_date') . ') = ' . $db->quote($dateOnly))
        ->order($db->quoteName('draw_date') . ' DESC');

    $db->setQuery($query);
    $rows = $db->loadAssocList();

    // Fallback: retry without game_id filter in case the draw table uses a different game_id value
    if (empty($rows)) {
        $fallbackQuery = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName($db->replacePrefix($tbl)))
            ->where('DATE(' . $db->quoteName('draw_date') . ') = ' . $db->quote($dateOnly))
            ->order($db->quoteName('draw_date') . ' DESC');

        $db->setQuery($fallbackQuery);
        $rows = $db->loadAssocList();
    }

    if (empty($rows)) {
        return null;
    }

    // Pick best row:
    // - Prefer row that has main numbers populated (avoid placeholder rows)
    // - If want=midday prefer earlier time; if want=evening prefer later time
    $fields = getDrawFields($gameId);
    $mainCols = $fields['main'] ?? [];

    $bestRow = null;
    $bestScore = -999999;

    foreach ($rows as $r) {
        $mainHasAny = 0;

        // Some tables may have normalized keys (main_0..)
        $hasNormMain = array_key_exists('main_0', $r);
        if ($hasNormMain) {
            for ($i = 0; $i < 25; $i++) {
                $k = 'main_' . $i;
                if (isset($r[$k]) && $r[$k] !== '' && $r[$k] !== null) {
                    $v = (int)$r[$k];
                    if ($v) { $mainHasAny = 1; break; }
                }
            }
        } else {
            foreach ($mainCols as $col) {
                if (isset($r[$col]) && $r[$col] !== '' && $r[$col] !== null) {
                    $v = (int)$r[$col];
                    if ($v) { $mainHasAny = 1; break; }
                }
            }
        }

        $score = 0;
        if ($mainHasAny) {
            $score += 1000;
        } else {
            $score -= 1000;
        }

        $t = 0;
        if (!empty($r['draw_date'])) {
            $tt = strtotime((string)$r['draw_date']);
            if ($tt !== false) {
                $t = (int)date('His', $tt);
            }
        }

        if ($want === 'midday') {
            $score += (235959 - $t);
        } elseif ($want === 'evening') {
            $score += $t;
        } else {
            $score += $t;
        }

        if ($score > $bestScore) {
            $bestScore = $score;
            $bestRow = $r;
        }
    }

    return $bestRow;
}
/**
 * getDrawFields: For a given gameId, read 'lottery_skip_config.json' to find which columns in the draw table
 * represent 'main_ball_columns' and 'extra_ball_column'.
 *
 * @param   mixed $gameId  Lottery game ID
 * @return  array          ['main' => array of column names, 'extra' => column name or null]
 */
function getDrawFields($gameId)
{
    $configFile = JPATH_ROOT . '/lottery_skip_config.json';
    if (!file_exists($configFile)) {
        return [];
    }

    $json = json_decode(file_get_contents($configFile), true);
    if (json_last_error() !== JSON_ERROR_NONE || empty($json['lotteries'][$gameId]['lotteryConfig'])) {
        return [];
    }

    $config   = $json['lotteries'][$gameId]['lotteryConfig'];
    $mainCols = $config['main_ball_columns'] ?? [];
    $extraCol = $config['extra_ball_column'] ?? null;

    // Normalize mains
    if (!is_array($mainCols)) {
        $mainCols = [];
    }
    $mainCols = array_values(array_filter(array_map('trim', $mainCols), function ($v) {
        return $v !== '';
    }));

    // Normalize extra
    if (is_string($extraCol)) {
        $extraCol = trim($extraCol);
        if ($extraCol === '') {
            $extraCol = null;
        }
    }
    if (!is_string($extraCol)) {
        $extraCol = null;
    }

    // Safety: if extra column equals one of the main columns, disable it
    if ($extraCol !== null && in_array($extraCol, $mainCols, true)) {
        $extraCol = null;
    }

    return [
        'main'  => $mainCols,
        'extra' => $extraCol
    ];
}

/**
 * Return the proper label for the extra/bonus ball for a given game.
 * Checks config first, then falls back to lottery-name heuristics.
 *
 * @param  int|string  $gameId       Game ID (used for config lookup)
 * @param  string      $lotteryName  Human-readable lottery name
 * @param  array       $configData   Parsed lottery_skip_config.json
 * @return string                    e.g. "Powerball", "Mega Ball", "Extra Ball"
 */
function getExtraBallLabel($gameId, string $lotteryName, array $configData = []): string
{
    // TODO: implement
        return 'Extra Ball';
}

/**
 * getActualDrawNumbers: Return actual draw main numbers and extra ball for a game/date.
 * This helper centralizes draw lookup and normalization across drawMap and DB.
 * It first looks up a row in the preloaded $drawMap keyed by "gameId|YYYY-mm-dd".
 * If not found it falls back to getDrawByDate().  It then uses getDrawFields()
 * to decode the main and extra columns.
 *
 * @param int              $gameId   Lottery game ID
 * @param string           $drawDate Draw date (any strtotime-parsable string)
 * @param array            $drawMap  Preloaded draw rows keyed by "gameId|YYYY-mm-dd"
 * @param JDatabaseDriver  $db       Joomla DB object for fallback queries
 * @return array                    ['main' => array<int>, 'extra' => int, 'found' => bool]
 */
function getActualDrawNumbers($gameId, $drawDate, array $drawMap, $db)
{
    // Normalize date to YYYY-MM-DD for consistent keys.
    $normDate = '';
    try {
        $dt = new DateTime((string)$drawDate);
        $normDate = $dt->format('Y-m-d');
    } catch (Exception $e) {
        $normDate = (string)$drawDate;
    }

    // Try to fetch from drawMap.  First an exact key, then any matching date.
    $drawRow = null;
    $key = (int)$gameId . '|' . $normDate;
    if (!empty($drawMap) && isset($drawMap[$key])) {
        $drawRow = $drawMap[$key];
    } elseif (!empty($drawMap)) {
        foreach ($drawMap as $dk => $row) {
            $pos = strrpos((string)$dk, '|');
            if ($pos !== false && substr((string)$dk, $pos + 1) === $normDate) {
                $drawRow = $row;
                break;
            }
        }
    }

    // Fallback to DB if not found in drawMap.
    if (!$drawRow) {
        $drawRow = getDrawByDate($gameId, $drawDate, $db);
    }
    if (!$drawRow) {
        return ['main' => [], 'extra' => 0, 'found' => false];
    }

    // Determine main and extra columns.
    $fields   = getDrawFields($gameId);
    $mainCols = $fields['main']  ?? [];
    $extraCol = $fields['extra'] ?? null;

    // Collect main numbers.
    $actualMain = [];
    $hasNormMain = array_key_exists('main_0', $drawRow);
    if ($hasNormMain) {
        for ($i = 0; $i < 25; $i++) {
            $k = 'main_' . $i;
            if (isset($drawRow[$k]) && $drawRow[$k] !== '' && $drawRow[$k] !== null) {
                $v = (int)$drawRow[$k];
                if ($v) $actualMain[] = $v;
            }
        }
    } else {
        foreach ($mainCols as $col) {
            if (isset($drawRow[$col]) && $drawRow[$col] !== '' && $drawRow[$col] !== null) {
                $v = (int)$drawRow[$col];
                if ($v) $actualMain[] = $v;
            }
        }
    }

    // Collect extra/bonus number.
    $actualExtra = 0;
    if ($hasNormMain) {
        if ($extraCol !== null && isset($drawRow['extra_ball']) && $drawRow['extra_ball'] !== '' && $drawRow['extra_ball'] !== null) {
            $actualExtra = (int)$drawRow['extra_ball'];
        }
    } else {
        if ($extraCol && isset($drawRow[$extraCol]) && $drawRow[$extraCol] !== '' && $drawRow[$extraCol] !== null) {
            $actualExtra = (int)$drawRow[$extraCol];
        }
    }

    return ['main' => $actualMain, 'extra' => $actualExtra, 'found' => true];
}

/**
 * getDrawAndScoreRun: Single source-of-truth for draw lookup + hit scoring.
 *
 * Uses the same draw-field mapping and number-extraction rules as the prediction
 * cards, so CRR and Best-Opps scoring always agree with what the cards display.
 *
 * Results are cached per game_id|date for the request lifetime to avoid
 * repeated DB queries inside tight loops.
 *
 * @param JDatabaseDriver  $db
 * @param int|string       $gameId
 * @param string           $drawDate    Any strtotime-parsable string (Y-m-d or datetime)
 * @param array            $predMain    Predicted main numbers (ints; zeros are ignored)
 * @param int              $predExtra   Predicted extra ball (0 = not set / not present; default: 0)
 * @return array {
 *   has_draw  : bool     - true iff a draw row exists AND has at least one main number
 *   drawMain  : int[]    - actual drawn main numbers (in column order)
 *   drawExtra : int[]    - [extra_ball_value] or [] if none
 *   hits_main : int      - count of predMain values found in drawMain
 *   hits_extra: int      - 1 if predExtra matches the drawn extra ball, else 0
 * }
 */

function scoreRunAgainstDraw($db, $gameId, $drawDate, array $predMain, $predExtra = 0, array $drawMap = []): array
{
    static $__drawCache = [];

    $empty = [
        'has_draw' => false,
        'drawMain' => [],
        'drawExtra' => [],
        'hits_main' => 0,
        'hits_extra' => 0,
        'norm_date' => '',
        'draw_found' => false,
        'reason' => 'awaiting: invalid date'
    ];

    $ts = strtotime((string)$drawDate);
    if ($ts === false) {
        return $empty;
    }
    $normDate = date('Y-m-d', $ts);
    $empty['norm_date'] = $normDate;
    $empty['reason'] = 'awaiting: draw not found';

    // Include subtype hint in cache key to avoid midday/evening collisions on same Y-m-d
    $dateStr = strtolower((string)$drawDate);
    $subKey = '';
    if (strpos($dateStr, 'midday') !== false || strpos($dateStr, 'noon') !== false || strpos($dateStr, 'day') !== false) {
        $subKey = 'midday';
    } elseif (strpos($dateStr, 'evening') !== false || strpos($dateStr, 'night') !== false || strpos($dateStr, 'pm') !== false) {
        $subKey = 'evening';
    }

    $cacheKey = (int)$gameId . '|' . $normDate . '|' . $subKey;

    if (!array_key_exists($cacheKey, $__drawCache)) {
        // Prefer the preloaded $drawMap (normalized main_0/main_1/extra_ball keys) so that
        // regular lotteries without extra balls are scored using the same data source as
        // the prediction cards, avoiding getDrawFields() column-name mismatches.
        $mapKey = (int)$gameId . '|' . $normDate;
        if (!empty($drawMap) && isset($drawMap[$mapKey])) {
            $__drawCache[$cacheKey] = $drawMap[$mapKey];
        } else {
            $__drawCache[$cacheKey] = getDrawByDate($gameId, $drawDate, $db);
        }
    }

    $draw = $__drawCache[$cacheKey];
        if (!$draw) {
        return $empty;
    }

    $fields   = getDrawFields($gameId);
    $mainCols = $fields['main'] ?? [];
    $extraCol = $fields['extra'] ?? null;

    $drawMain = [];
    $hasNormMain = array_key_exists('main_0', $draw);

    if ($hasNormMain) {
        for ($i = 0; $i < 25; $i++) {
            $k = 'main_' . $i;
            if (isset($draw[$k]) && $draw[$k] !== '' && $draw[$k] !== null) {
                $v = (int)$draw[$k];
                if ($v) $drawMain[] = $v;
            }
        }
    } else {
        foreach ($mainCols as $col) {
            if (isset($draw[$col]) && $draw[$col] !== '' && $draw[$col] !== null) {
                $v = (int)$draw[$col];
                if ($v) $drawMain[] = $v;
            }
        }
    }

    if (empty($drawMain)) {
        $empty['draw_found'] = true;
        $empty['reason'] = 'awaiting: draw row has no main numbers';
        return $empty;
    }

    $drawExtra = [];
    if ($hasNormMain) {
        if ($extraCol !== null && isset($draw['extra_ball']) && $draw['extra_ball'] !== '' && $draw['extra_ball'] !== null) {
            $v = (int)$draw['extra_ball'];
            if ($v) $drawExtra[] = $v;
        }
    } else {
        if ($extraCol && isset($draw[$extraCol]) && $draw[$extraCol] !== '' && $draw[$extraCol] !== null) {
            $v = (int)$draw[$extraCol];
            if ($v) $drawExtra[] = $v;
        }
    }

    $hitsMain = 0;
    foreach ($predMain as $n) {
        if ($n !== 0 && in_array($n, $drawMain, true)) {
            $hitsMain++;
        }
    }

    $hitsExtra = 0;
    $predExtra = (int)$predExtra;
    if ($predExtra > 0 && !empty($drawExtra) && in_array($predExtra, $drawExtra, true)) {
        $hitsExtra = 1;
    }

    return [
        'has_draw' => true,
        'drawMain' => $drawMain,
        'drawExtra' => $drawExtra,
        'hits_main' => $hitsMain,
        'hits_extra' => $hitsExtra,
        'norm_date' => $normDate,
        'draw_found' => true,
        'reason' => 'scored'
    ];
}

function getDrawAndScoreRun($db, $gameId, $drawDate, array $predMain, $predExtra = 0, array $drawMap = [])
{
    return scoreRunAgainstDraw($db, $gameId, $drawDate, $predMain, $predExtra, $drawMap);
}

/**
 * resolvePredictionLines: Canonical resolver for predicted ball numbers from a saved run.
 *
 * Returns an array of scorable prediction lines, each as:
 *   ['main' => [int...], 'extra' => [int...]]
 *
 * Priority order:
 *   1. top_combos_json (if present and valid JSON): authoritative predicted lines.
 *      Supports formats: flat array of ints, list with 'main'/'numbers' keys.
 *   2. position_combinations (if present and valid JSON): derive lines from positional data.
 *   3. main_numbers fallback: used ONLY when the count matches the expected main ball count
 *      from config. A larger count (pool/ranked list) is rejected; [] is returned.
 *
 * For games with no extra ball, all 'extra' arrays will be [].
 * Returns [] when no scorable lines can be resolved.
 *
 * @param  object  $savedRow    DB row from #__user_saved_numbers
 * @param  array   $lotteryCfg  Entry from lottery_skip_config.json['lotteries'][$gameId]
 * @return array                Array of lines: [['main' => int[], 'extra' => int[]], ...]
 */
function resolvePredictionLines($savedRow, array $lotteryCfg): array
{
    $lotteryConfig = $lotteryCfg['lotteryConfig'] ?? [];
    $cfgMainCols   = $lotteryConfig['main_ball_columns'] ?? [];
    if (!is_array($cfgMainCols)) {
        $cfgMainCols = [];
    }
    $expectedCount = count(array_filter(array_map('trim', $cfgMainCols), static function ($c) { return $c !== ''; }));
    $hasExtraBall  = !empty($lotteryConfig['extra_ball_column']);

    $toInts = static function (array $raw): array {
        return array_values(array_filter(array_map('intval', $raw), static function ($n) { return $n > 0; }));
    };

    // --- 1. top_combos_json ---
    if (!empty($savedRow->top_combos_json)) {
        $decoded = json_decode((string)$savedRow->top_combos_json, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $lines = [];
            foreach ($decoded as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                if (array_key_exists('main', $entry)) {
                    $mainNums  = $toInts((array)$entry['main']);
                    $extraNums = $hasExtraBall ? $toInts((array)($entry['extra'] ?? [])) : [];
                } elseif (array_key_exists('numbers', $entry)) {
                    $mainNums  = $toInts((array)$entry['numbers']);
                    $extraNums = $hasExtraBall ? $toInts((array)($entry['extra'] ?? [])) : [];
                } else {
                    $mainNums  = $toInts(array_values($entry));
                    $extraNums = [];
                }
                if (!empty($mainNums)) {
                    $lines[] = ['main' => $mainNums, 'extra' => $extraNums];
                }
            }
            if (!empty($lines)) {
                return $lines;
            }
        }
    }

    // --- 2. position_combinations ---
    if (!empty($savedRow->position_combinations)) {
        $decoded = json_decode((string)$savedRow->position_combinations, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $lines = [];
            foreach ($decoded as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $mainNums = $toInts(array_values($entry));
                if (!empty($mainNums)) {
                    $lines[] = ['main' => $mainNums, 'extra' => []];
                }
            }
            if (!empty($lines)) {
                return $lines;
            }
        }
    }

    // --- 3. main_numbers fallback (only when count matches expected ball count) ---
    $mainRaw  = array_map('intval', explode(',', (string)($savedRow->main_numbers ?? '')));
    $mainNums = $toInts($mainRaw);

    if ($expectedCount > 0 && count($mainNums) !== $expectedCount) {
        // Count mismatch: this is a pool or ranked list, not a single scorable line.
        return [];
    }

    if (empty($mainNums)) {
        return [];
    }

    $extraNums = [];
    if ($hasExtraBall) {
        if (!empty($savedRow->extra_ball_numbers)) {
            $extraNums = $toInts(array_map('intval', explode(',', (string)$savedRow->extra_ball_numbers)));
        } elseif (!empty($savedRow->extra_number)) {
            $v = (int)$savedRow->extra_number;
            if ($v > 0) {
                $extraNums = [$v];
            }
        } elseif (!empty($savedRow->extra_numbers)) {
            $extraNums = $toInts(array_map('intval', explode(',', (string)$savedRow->extra_numbers)));
        } elseif (!empty($savedRow->bonus_number)) {
            $v = (int)$savedRow->bonus_number;
            if ($v > 0) {
                $extraNums = [$v];
            }
        }
    }

    return [['main' => $mainNums, 'extra' => $extraNums]];
}

/**
 * resolveDrawNumbers: Canonical resolver for actual drawn ball numbers.
 *
 * Uses lottery_skip_config.json as the single source of truth for table name,
 * main ball columns, and extra ball column. No runtime column guessing.
 *
 * Distinct error categories returned in 'reason':
 *   config_error:      missing/incomplete config entry (table, columns, or date)
 *   draw_not_found:    draw table queried but no row found for this game/date
 *   draw_parse_error:  row found but configured main columns are missing/empty
 *   scored:            draw extracted successfully
 *
 * Results are cached per (gameId|Y-m-d) for the request lifetime.
 *
 * @param  JDatabaseDriver  $db
 * @param  int              $gameId
 * @param  string           $drawDate     Any strtotime-parsable date string
 * @param  array            $lotteryCfg   Entry from lottery_skip_config.json['lotteries'][$gameId]
 * @return array  ['has_draw'=>bool, 'main'=>int[], 'extra'=>int[], 'reason'=>string, 'draw_row'=>array|null]
 */
function resolveDrawNumbers($db, $gameId, $drawDate, array $lotteryCfg, array $drawMap = []): array
{
    static $__rdnCache = [];

    $notFound = ['has_draw' => false, 'main' => [], 'extra' => [], 'reason' => 'draw_not_found', 'draw_row' => null];

    // --- Validate config ---
    $tblName  = isset($lotteryCfg['dbCol']) ? trim((string)$lotteryCfg['dbCol']) : '';
    $lcfg     = $lotteryCfg['lotteryConfig'] ?? [];
    $rawCols  = $lcfg['main_ball_columns'] ?? [];
    $mainCols = is_array($rawCols)
        ? array_values(array_filter(array_map('trim', $rawCols), static function ($c) { return $c !== ''; }))
        : [];
    $extraColRaw = isset($lcfg['extra_ball_column']) ? trim((string)$lcfg['extra_ball_column']) : '';
    $extraCol    = $extraColRaw !== '' ? $extraColRaw : null;

    if ($tblName === '') {
        return array_merge($notFound, ['reason' => 'config_error: missing table name (dbCol)']);
    }
    if (empty($mainCols)) {
        return array_merge($notFound, ['reason' => 'config_error: no main_ball_columns configured']);
    }

    // --- Normalize date ---
    $ts = strtotime((string)$drawDate);
    if ($ts === false) {
        return array_merge($notFound, ['reason' => 'config_error: invalid draw date']);
    }
    $normDate = date('Y-m-d', $ts);
    $cacheKey = (int)$gameId . '|' . $normDate;

    if (array_key_exists($cacheKey, $__rdnCache)) {
        return $__rdnCache[$cacheKey];
    }

    // --- Layer 1: preloaded drawMap (normalized main_0/main_1/extra_ball keys from UNION query) ---
    // This path is the most reliable because the UNION was built using the same config table+columns.
    $drawRow = null;
    if (!empty($drawMap) && isset($drawMap[$cacheKey])) {
        $candidate = $drawMap[$cacheKey];
        for ($__i = 0; $__i < 25; $__i++) {
            $__mk = 'main_' . $__i;
            if (array_key_exists($__mk, $candidate) && $candidate[$__mk] !== null && $candidate[$__mk] !== '') {
                $drawRow = $candidate;
                break;
            }
        }
    }

    // --- Layer 2: direct DB query via getDrawByDate (raw column names from SELECT *) ---
    if ($drawRow === null) {
        try {
            $drawRow = getDrawByDate($gameId, $normDate, $db);
        } catch (\Exception $e) {
            $drawRow = null;
        }
    }

    if (!$drawRow) {
        $__rdnCache[$cacheKey] = $notFound;
        return $notFound;
    }

    // --- Extract main numbers ---
    // For drawMap rows use normalized main_0/main_1 keys; for raw DB rows use configured column names.
    $main    = [];
    $hasNorm = array_key_exists('main_0', $drawRow);

    if ($hasNorm) {
        for ($__i = 0; $__i < 25; $__i++) {
            $__mk = 'main_' . $__i;
            if (isset($drawRow[$__mk]) && $drawRow[$__mk] !== '' && $drawRow[$__mk] !== null) {
                $v = (int)$drawRow[$__mk];
                if ($v > 0) {
                    $main[] = $v;
                }
            }
        }
    } else {
        foreach ($mainCols as $col) {
            if (isset($drawRow[$col]) && $drawRow[$col] !== '' && $drawRow[$col] !== null) {
                $v = (int)$drawRow[$col];
                if ($v > 0) {
                    $main[] = $v;
                }
            }
        }
    }

    if (empty($main)) {
        $result = [
            'has_draw' => false,
            'main'     => [],
            'extra'    => [],
            'reason'   => 'draw_parse_error: main columns not found or empty in draw row',
            'draw_row' => null,
        ];
        $__rdnCache[$cacheKey] = $result;
        return $result;
    }

    // --- Extract extra ball ([] for no-extra-ball games) ---
    $extra = [];
    if ($hasNorm) {
        // drawMap row: extra_ball normalized key
        if ($extraCol !== null
            && array_key_exists('extra_ball', $drawRow)
            && $drawRow['extra_ball'] !== null
            && $drawRow['extra_ball'] !== '') {
            $v = (int)$drawRow['extra_ball'];
            if ($v > 0) {
                $extra = [$v];
            }
        }
    } else {
        // raw DB row: use configured extra column name
        if ($extraCol !== null
            && isset($drawRow[$extraCol])
            && $drawRow[$extraCol] !== ''
            && $drawRow[$extraCol] !== null) {
            $v = (int)$drawRow[$extraCol];
            if ($v > 0) {
                $extra = [$v];
            }
        }
    }

    $result = ['has_draw' => true, 'main' => $main, 'extra' => $extra, 'reason' => 'scored', 'draw_row' => null];
    $__rdnCache[$cacheKey] = $result;
    return $result;
}

/**
 * Map a US state/territory name ? USPS abbreviation (uppercase).
 * Falls back to first two letters if unknown. Input may be mixed case.
 */
function stateToAbbrev(string $stateName): string
{
    static $map = [
        'alabama'=>'AL','alaska'=>'AK','arizona'=>'AZ','arkansas'=>'AR','california'=>'CA','colorado'=>'CO','connecticut'=>'CT','delaware'=>'DE',
        'district of columbia'=>'DC','washington dc'=>'DC','dc'=>'DC',
        'florida'=>'FL','georgia'=>'GA','hawaii'=>'HI','idaho'=>'ID','illinois'=>'IL','indiana'=>'IN','iowa'=>'IA','kansas'=>'KS','kentucky'=>'KY','louisiana'=>'LA',
        'maine'=>'ME','maryland'=>'MD','massachusetts'=>'MA','michigan'=>'MI','minnesota'=>'MN','mississippi'=>'MS','missouri'=>'MO','montana'=>'MT',
        'nebraska'=>'NE','nevada'=>'NV','new hampshire'=>'NH','new jersey'=>'NJ','new mexico'=>'NM','new york'=>'NY','north carolina'=>'NC','north dakota'=>'ND',
        'ohio'=>'OH','oklahoma'=>'OK','oregon'=>'OR','pennsylvania'=>'PA','rhode island'=>'RI','south carolina'=>'SC','south dakota'=>'SD',
        'tennessee'=>'TN','texas'=>'TX','utah'=>'UT','vermont'=>'VT','virginia'=>'VA','washington'=>'WA','west virginia'=>'WV','wisconsin'=>'WI','wyoming'=>'WY',
        // common territories some datasets include
        'puerto rico'=>'PR','guam'=>'GU','american samoa'=>'AS','northern mariana islands'=>'MP','us virgin islands'=>'VI'
    ];
    $k = strtolower(trim($stateName));
    if (isset($map[$k])) return $map[$k];
    // If input is already an abbreviation like "NY"
    if (strlen($stateName) === 2 && ctype_alpha($stateName)) return strtoupper($stateName);
    $two = strtoupper(preg_replace('/[^A-Za-z]/','', $stateName));
    return substr($two, 0, 2) ?: 'US';
}

/**
 * Build a safe logo path using your format:
 * /images/lottodb/us/{ST}/{game-name-slug}.png
 * Returns ['path' => string, 'exists' => bool, 'alt' => string]
 */
function buildLotteryLogoPath(string $stateName, string $gameName): array
{
    $abbrUpper = stateToAbbrev($stateName);       // e.g., AZ
    $abbrLower = strtolower($abbrUpper);          // e.g., az

    // Helper to convert numbers to words in a string
    $numberToWord = function($str) {
        $numbers = [
            '0'=>'zero','1'=>'one','2'=>'two','3'=>'three','4'=>'four',
            '5'=>'five','6'=>'six','7'=>'seven','8'=>'eight','9'=>'nine',
            '10'=>'ten','11'=>'eleven','12'=>'twelve'
        ];
        // Replace standalone numbers with words
        return preg_replace_callback('/\b(\d+)\b/', function($m) use ($numbers) {
            return isset($numbers[$m[1]]) ? $numbers[$m[1]] : $m[1];
        }, $str);
    };

    // Your exact spec: str_replace(' ','-', strtolower($gName))
    $slugExact = str_replace(' ', '-', strtolower($gameName));
    
    // Variation with numbers converted to words (e.g., "Cash 5" ? "cash-five")
    $slugWithWords = str_replace(' ', '-', strtolower($numberToWord($gameName)));

    // A safer fallback slug (strip punctuation, compress dashes)
    $slugSafe  = strtolower(trim(preg_replace('/\s+/', '-', preg_replace('/[^A-Za-z0-9 ]/', '', $gameName))));
    $slugSafe  = preg_replace('/-+/', '-', $slugSafe);

    // Additional variations for better matching
    $slugNoSpaces = str_replace(' ', '', strtolower($gameName));  // e.g., "cash5"
    $slugWithState = strtolower($abbrLower . '-' . preg_replace('/\s+/', '-', preg_replace('/[^A-Za-z0-9 ]/', '', $gameName)));
    $slugWithState = preg_replace('/-+/', '-', $slugWithState);  // e.g., "tx-cash-5"

    // Build candidates with multiple file extensions
    // Priority: lowercase state folder (matching fantasy-5-evening.png and cash-five.png format)
    $extensions = ['png', 'jpg', 'jpeg', 'svg'];
    $candidates = [];
    
    // Primary format: /images/lottodb/us/{lowercase-state}/{lowercase-name-with-hyphens}.{ext}
    // Try with numbers as words first (e.g., cash-five.png), then with numerals (e.g., cash-5.png)
    foreach ($extensions as $ext) {
        $candidates[] = '/images/lottodb/us/' . $abbrLower . '/' . $slugWithWords . '.' . $ext;
        $candidates[] = '/images/lottodb/us/' . $abbrLower . '/' . $slugExact . '.' . $ext;
    }
    
    // Fallback: safe slug variation
    foreach ($extensions as $ext) {
        $candidates[] = '/images/lottodb/us/' . $abbrLower . '/' . $slugSafe . '.' . $ext;
    }
    
    // Additional fallbacks: uppercase state folder (for backwards compatibility)
    foreach ($extensions as $ext) {
        $candidates[] = '/images/lottodb/us/' . $abbrUpper . '/' . $slugWithWords . '.' . $ext;
        $candidates[] = '/images/lottodb/us/' . $abbrUpper . '/' . $slugExact . '.' . $ext;
        $candidates[] = '/images/lottodb/us/' . $abbrUpper . '/' . $slugSafe . '.' . $ext;
    }
    
    // Even more fallbacks: no spaces, with state prefix
    foreach ($extensions as $ext) {
        $candidates[] = '/images/lottodb/us/' . $abbrLower . '/' . $slugNoSpaces . '.' . $ext;
        $candidates[] = '/images/lottodb/us/' . $abbrLower . '/' . $slugWithState . '.' . $ext;
    }

    // Remove duplicates while preserving order
    $candidates = array_values(array_unique($candidates));

    // Debug logging for troubleshooting logo issues
    // Set debugLog to true in config to enable, or check for a debug query parameter
    $debugLog = false;
    if ($debugLog) {
        error_log("Logo Debug - State: $stateName, Game: $gameName");
        error_log("Logo Debug - Abbr: $abbrUpper/$abbrLower, SlugExact: $slugExact, SlugSafe: $slugSafe");
        foreach ($candidates as $idx => $rel) {
            $fullPath = JPATH_ROOT . $rel;
            $exists = is_file($fullPath) ? 'EXISTS' : 'NOT FOUND';
            error_log("Logo Debug - Candidate $idx: $rel [$exists]");
        }
    }

    foreach ($candidates as $rel) {
        if (is_file(JPATH_ROOT . $rel)) {
            return ['path' => $rel, 'exists' => true, 'alt' => $gameName . ' logo'];
        }
    }

    // None found ? return the first (your canonical) for <img src> if you prefer,
    // but we'll mark exists=false so the badge shows instead.
    return ['path' => $candidates[0], 'exists' => false, 'alt' => $gameName . ' logo'];
}

// --------------------------------------------------
// Load Data: Saved Predictions
// --------------------------------------------------

// 1) Fetch saved predictions with timestamps
$q = $db->getQuery(true);
$q->select(array(
    'sn.id','sn.lottery_id','sn.next_draw_date','sn.source','sn.label','sn.main_numbers',
    'sn.extra_ball_numbers','sn.date_saved','sn.generated_at',

    'sn.epochs','sn.batch_size','sn.dropout_rate','sn.learning_rate',
    'sn.activation_function','sn.hidden_layers',
    'sn.recency_decay',
    'sn.walks','sn.burn_in','sn.laplace_k','sn.decay','sn.chain_len',
    'sn.draws_analyzed','sn.freq_weight','sn.skip_weight','sn.hist_weight',
    'sn.draws_used','sn.skip_window',
    'sn.auto_tune','sn.tune_used','sn.best_window',
    'sn.skai_blend_skip_pct','sn.skai_blend_ai_pct',
    'sn.sampling_temperature','sn.diversity_penalty','sn.gap_scale',
    'sn.skai_window_size','sn.skai_run_mode',
    'sn.skai_top_n_numbers','sn.skai_top_n_combos',
    'sn.digit_probabilities',
    'l.game_id','l.name AS lottery_name',
    'sn.pure_mode'


));
$q->from($db->quoteName('#__user_saved_numbers', 'sn'));
$q->join('LEFT', $db->quoteName('#__lotteries', 'l') . ' ON sn.lottery_id = l.lottery_id');

$q->where('sn.user_id = ' . (int) $user->id);
$q->order('sn.next_draw_date ' . $predSortDir . ', sn.source ASC, COALESCE(sn.generated_at,sn.date_saved) ' . $predSortDir);


$db->setQuery($q);
$savedSets = $db->loadObjectList() ?: [];

// Load previously saved best settings + notes per lottery for this user
$savedBestSettings = [];
if (!empty($savedSets)) {
    $__bsIds = array_unique(array_map(static function($s) { return (int)$s->lottery_id; }, $savedSets));
    if ($__bsIds) {
        $__bsQ = $db->getQuery(true)
            ->select($db->quoteName(['lottery_id','settings_summary','notes']))
            ->from($db->quoteName('#__skai_best_settings'))
            ->where($db->quoteName('user_id')    . ' = ' . (int)$user->id)
            ->where($db->quoteName('lottery_id') . ' IN (' . implode(',', $__bsIds) . ')');
        $db->setQuery($__bsQ);
        foreach (($db->loadAssocList() ?: []) as $__bsRow) {
            $savedBestSettings[(int)$__bsRow['lottery_id']] = $__bsRow;
        }
    }
}

// Build URL and meta lookups for all lotteries appearing in saved predictions
$lottoUrlsById  = [];
$lottoMetaById  = [];

$neededIds = array_unique(array_map(static function($s) { return (int)$s->lottery_id; }, $savedSets));

// Figure out which needed IDs are missing from $lottoUrlsById
$currentUrlIds = array_map('intval', array_keys($lottoUrlsById));
$missingIds    = array_values(array_diff($neededIds, $currentUrlIds));

if (!empty($missingIds)) {
    // Build IN() list safely (ints only)
    $idList = implode(',', array_map('intval', $missingIds));

    $q = $db->getQuery(true)
        ->select([
            'l.lottery_id',
            'l.lottery_urls',
            'l.name AS lottery_name',
            's.name AS state_name',
            'c.name AS country_name'
        ])
        ->from($db->quoteName('#__lotteries', 'l'))
        ->join('LEFT', $db->quoteName('#__states', 's') . ' ON l.state_id = s.state_id')
        ->join('LEFT', $db->quoteName('#__countries', 'c') . ' ON s.country_id = c.country_id')
        ->where('l.lottery_id IN (' . $idList . ')');

    $db->setQuery($q);
    $rows = $db->loadAssocList() ?: [];

    foreach ($rows as $row) {
        $id  = (int)($row['lottery_id'] ?? 0);
        $url = trim((string)($row['lottery_urls'] ?? ''));
        if ($id > 0 && $url !== '') {
            $lottoUrlsById[$id] = $url;
        }
        // Also backfill state/country meta if missing
        if (!isset($lottoMetaById[$id])) {
            $lottoMetaById[$id] = [
                'state'   => $row['state_name']   ?? '',
                'country' => $row['country_name'] ?? ''
            ];
        } else {
            // Fill gaps if empty
            if (empty($lottoMetaById[$id]['state']))   { $lottoMetaById[$id]['state']   = $row['state_name']   ?? ''; }
            if (empty($lottoMetaById[$id]['country'])) { $lottoMetaById[$id]['country'] = $row['country_name'] ?? ''; }
        }
    }
}

// --------------------------------------------------------------
// Normalize any non-AI/MCMC/SKAI source back to 'skip_hit'
// so that every weight-variant run still shows as "Skip&Hit"
// --------------------------------------------------------------
foreach ($savedSets as $s) {
    // Map DB value to our 4 buckets
    if ($s->source === 'heatmap_manual') {
        $s->source = 'heatmap';
    } elseif (
        $s->source !== 'ai_prediction' &&
        $s->source !== 'mcmc_prediction' &&
        $s->source !== 'skip_hit' &&
        $s->source !== 'heatmap' &&
        $s->source !== 'skai_prediction'   // NEW: treat SKAI as its own method
    ) {
        // Unknown ? keep old behavior
        $s->source = 'skip_hit';
    }
}

// 2) Load config for actual draws
$configPath = JPATH_ROOT . '/lottery_skip_config.json';
$configData = file_exists($configPath)
    ? json_decode(file_get_contents($configPath),true)
    : [];

// 3) Group by lottery+date
/**
 * Group saved prediction sets by lottery and draw date,
 * and derive draw table metadata per game_id for later lookups.
 */
$groups    = array();
$tableInfo = array();

foreach ($savedSets as $s) {
    // Group key uses normalized Y-m-d to avoid datetime vs date mismatch
    $__normTs = strtotime((string)$s->next_draw_date);
    $__normDt = ($__normTs !== false) ? date('Y-m-d', $__normTs) : (string)$s->next_draw_date;
    $key = $s->lottery_id . '|' . $__normDt;

    if (!isset($groups[$key])) {
        $groups[$key] = array(
            'lottery_id'   => $s->lottery_id,
            'game_id'      => $s->game_id,
            'lottery_name' => $s->lottery_name,
            'draw_date'    => $__normDt,
            'preds'        => array(),
        );
    }

    $groups[$key]['preds'][] = $s;

    // Build tableInfo by game_id, not lottery_id, using lottery_skip_config.json.
    if (!isset($tableInfo[$s->game_id])) {
        $spec = $configData['lotteries'][$s->game_id] ?? array();

        $tableInfo[$s->game_id] = array(
            'table' => $spec['dbCol'] ?? '',
            'main'  => $spec['lotteryConfig']['main_ball_columns'] ?? array(),
            'extra' => $spec['lotteryConfig']['extra_ball_column'] ?? null,
        );
    }
}

// Compute the maximum number of main balls and whether any game uses an extra ball.
$maxMain  = 0;
$hasExtra = false;

foreach ($tableInfo as $info) {
    $mainCount = count($info['main']);

    if ($mainCount > $maxMain) {
        $maxMain = $mainCount;
    }

    if (!empty($info['extra'])) {
        $hasExtra = true;
    }
}

// Build UNION ALL query parts for actual draws and construct a preloaded draw map.
$unionParts = array();

foreach ($groups as $g) {
    $tblKeyInt = (int) ($g['game_id'] ?? 0);

    if (!$tblKeyInt || empty($tableInfo[$tblKeyInt]['table'])) {
        continue;
    }

    $tblName = $tableInfo[$tblKeyInt]['table'];
    $tbl     = $db->quoteName($db->replacePrefix($tblName));

    // Build column list: draw_date + aliased mains + optional aliased extra.
    $cols   = array();
    $cols[] = $db->quoteName('draw_date');

    for ($i = 0; $i < $maxMain; $i++) {
        if (!empty($tableInfo[$tblKeyInt]['main'][$i])) {
            // Alias each main ball column to a normalized name: main_0, main_1, �
            $cols[] = $db->quoteName($tableInfo[$tblKeyInt]['main'][$i]) . ' AS main_' . $i; // CHANGED: added alias
        } else {
            $cols[] = 'NULL AS main_' . $i;
        }
    }

    if ($hasExtra) {
        if (!empty($tableInfo[$tblKeyInt]['extra'])) {
            // Alias the extra ball to a normalized name: extra_ball.
            $cols[] = $db->quoteName($tableInfo[$tblKeyInt]['extra']) . ' AS extra_ball'; // CHANGED: added alias
        } else {
            $cols[] = 'NULL AS extra_ball';
        }
    }

    $cols        = implode(',', $cols);
    // Always use date-only format so DATE(draw_date) comparison is unambiguous in MySQL
    $escapedDate = $db->quote($g['draw_date']); // already normalized to Y-m-d above

    $unionParts[] =
        'SELECT ' . $tblKeyInt . ' AS game_id, ' . $cols . ', ' . $escapedDate . ' AS req_date'
        . ' FROM ' . $tbl
        . ' WHERE ' . $db->quoteName('game_id') . ' = ' . $tblKeyInt
        . ' AND DATE(' . $db->quoteName('draw_date') . ') = ' . $escapedDate;
}

$unionSql = implode(' UNION ALL ', $unionParts);

/**
 * Preload all requested draws into a normalized draw map:
 * $drawMap["<game_id>|<Y-m-d>"] = array(
 *     'game_id'    => int,
 *     'draw_date'  => 'Y-m-d ...',
 *     'main_0'     => int|null,
 *     'main_1'     => int|null,
 *     ...
 *     'extra_ball' => int|null,
 * );
 */
$drawMap = array();

if (!empty($unionSql)) {
    try {
        $db->setQuery($unionSql);
        $rows = (array) $db->loadAssocList();

        foreach ($rows as $row) {
            // Normalize requested date to Y-m-d for consistent keying.
            $reqDate = isset($row['req_date']) ? $row['req_date'] : $row['draw_date'];
            try {
                $dt      = new DateTime($reqDate);
                $norm    = $dt->format('Y-m-d');
            } catch (Exception $e) {
                // Fallback: use raw value if parsing fails.
                $norm = $reqDate;
            }

            $gId     = isset($row['game_id']) ? (int) $row['game_id'] : 0;
            if (!$gId) {
                continue;
            }

            $mapKey = $gId . '|' . $norm;

            // Store the entire row; rendering logic can map fields as needed.
            $drawMap[$mapKey] = $row;
        }
    } catch (RuntimeException $e) {
        // If UNION query fails for any reason, fall back to per-card getDrawByDate().
        $drawMap = array();
    }
}

// 5) Analysis types used to render per-method prediction columns.
$modules = array(
    array('key' => 'skip_hit',        'label' => 'Skip & Hit',       'icon' => '', 'tooltip' => 'Analyzes historical draw patterns'),
    array('key' => 'ai_prediction',   'label' => 'AI',               'icon' => '', 'tooltip' => 'Deep learning model trained on draw history'),
    array('key' => 'mcmc_prediction', 'label' => 'MCMC',             'icon' => '', 'tooltip' => 'Statistical sampling method'),
    array('key' => 'heatmap',         'label' => 'Frequency Map',    'icon' => '', 'tooltip' => 'Visual frequency analysis'),
    array('key' => 'skai_prediction', 'label' => 'SKAI',             'icon' => '', 'tooltip' => 'Combines multiple analytical methods'),
);

// ------------------------------------------------------------------
// 6) Compute Best Opportunities per lottery (Agreement / Top-Hits Ranking)
//    Results stored in $bestOpps[lottery_id] for rendering below.
// ------------------------------------------------------------------

// debug_hits flag: available here so awaiting-run debug data can be collected
$__debugHits = (int)$app->input->get('debug_hits', 0, 'INT') === 1;

// Shared comparator for ranking runs: returns negative/zero/positive (usort-compatible).
// Ranking rule: 1) hits desc  2) extra_hits desc  3) rank_score desc  4) run_id desc
$__rankCompare = static function (array $a, array $b): int {
    if ($a['hits'] !== $b['hits']) {
        return $b['hits'] - $a['hits'];
    }
    // extra_hits breaks ties before rank_score: "N main + extra" beats "N main + 0 extra"
    if ($a['extra_hits'] !== $b['extra_hits']) {
        return $b['extra_hits'] - $a['extra_hits'];
    }
    if ($a['rank_score'] !== $b['rank_score']) {
        return $b['rank_score'] - $a['rank_score'];
    }
    return $b['run_id'] - $a['run_id'];
};

$bestOpps = [];
foreach ($groups as $groupKey => $g) {
    $lid = (int)$g['lottery_id'];

    if (!isset($bestOpps[$lid])) {
        $bestOpps[$lid] = [
            'lottery_name'    => (string)($g['lottery_name'] ?? ''),
            'lottery_id'      => $lid,
            'game_id'         => (int)($g['game_id'] ?? 0),
            'state_name'      => (string)($lottoMetaById[$lid]['state'] ?? ''),
            'total_runs'      => 0,
            'best_agreement'  => [
                'score'    => -1,
                'draw_date'=> '',
                'numbers'  => [],
                'methods'  => [],
                'run_id'   => 0,
                'source'   => ''
            ],
            'best_rank'       => [
                'score'        => -1,
                'draw_date'    => '',
                'run_id'       => 0,
                'source'       => '',
                'hits'         => 0,
                'extra_hits'   => 0,
                'numbers'      => [],
                'extra_number' => 0,
            ],
            'all_scored_runs' => [],
            'awaiting_debug'  => [],
        ];
    }
    $bestOpps[$lid]['total_runs'] += count($g['preds']);

    // -- Agreement: per-run scoring (uses canonical prediction resolver) --
    $__aCfg = $configData['lotteries'][(int)$g['game_id']] ?? [];
    $allSourceNumPool = [];
    foreach ($g['preds'] as $s) {
        $src   = (string)$s->source;
        $lines = resolvePredictionLines($s, $__aCfg);
        if (!isset($allSourceNumPool[$src])) {
            $allSourceNumPool[$src] = [];
        }
        foreach ($lines as $__aln) {
            foreach ($__aln['main'] as $n) {
                $allSourceNumPool[$src][$n] = true;
            }
        }
    }
    foreach ($g['preds'] as $s) {
        $src      = (string)$s->source;
        $__sLines = resolvePredictionLines($s, $__aCfg);
        $__mySet  = [];
        foreach ($__sLines as $__sln) {
            foreach ($__sln['main'] as $n) {
                $__mySet[$n] = true;
            }
        }
        $myNums = array_keys($__mySet);
        $otherNums    = [];
        $otherSrcList = [];
        foreach ($allSourceNumPool as $otherSrc => $numSet) {
            if ($otherSrc !== $src) {
                foreach ($numSet as $n => $_) $otherNums[$n] = true;
                $otherSrcList[] = $otherSrc;
            }
        }
        $otherSrcList = array_unique($otherSrcList);

        $agreed = [];
        foreach ($myNums as $n) {
            if (isset($otherNums[$n])) $agreed[] = $n;
        }
        $score = count($agreed);
        $prev  = $bestOpps[$lid]['best_agreement'];
        $isBetter = ($score > $prev['score'])
            || ($score === $prev['score'] && count($otherSrcList) > count($prev['methods']))
            || ($score === $prev['score'] && count($otherSrcList) === count($prev['methods']) && (int)$s->id > (int)$prev['run_id']);
        if ($isBetter) {
            sort($agreed);
            $bestOpps[$lid]['best_agreement'] = [
                'score'     => $score,
                'draw_date' => (string)$g['draw_date'],
                'numbers'   => $agreed,
                'methods'   => $otherSrcList,
                'run_id'    => (int)$s->id,
                'source'    => $src,
            ];
        }
    }

    // -- Rank Strength (Top-Hits Ranking): canonical resolvers for draw and predictions --
    $__rsCfg  = $configData['lotteries'][(int)$g['game_id']] ?? [];
    $__rsDraw = resolveDrawNumbers($db, (int)$g['game_id'], (string)$g['draw_date'], $__rsCfg, $drawMap);

    foreach ($g['preds'] as $s) {
        if (!$__rsDraw['has_draw']) {
            if ($__debugHits) {
                $bestOpps[$lid]['awaiting_debug'][] = [
                    'run_id'    => (int)$s->id,
                    'game_id'   => (int)$g['game_id'],
                    'norm_date' => (string)$g['draw_date'],
                    'source'    => (string)$s->source,
                    'reason'    => $__rsDraw['reason'],
                ];
            }
            continue;
        }

        $__predLines = resolvePredictionLines($s, $__rsCfg);
        if (empty($__predLines)) {
            if ($__debugHits) {
                $bestOpps[$lid]['awaiting_debug'][] = [
                    'run_id'    => (int)$s->id,
                    'game_id'   => (int)$g['game_id'],
                    'norm_date' => (string)$g['draw_date'],
                    'source'    => (string)$s->source,
                    'reason'    => 'prediction_parse_error: no scorable lines resolved from saved run',
                ];
            }
            continue;
        }

        $actualMain  = $__rsDraw['main'];
        $actualExtra = $__rsDraw['extra'];

        // Score every resolved line; keep the best-scoring line for this run
        $predMain      = [];
        $predExtra     = 0;
        $mainHitCount  = 0;
        $extraHitCount = 0;
        $matchedMain   = [];
        $rankScore     = PHP_INT_MIN;

        foreach ($__predLines as $__pl) {
            $__lMain  = $__pl['main'];
            $__lExtra = $__pl['extra'][0] ?? 0;

            $__lHitsMain = 0;
            foreach ($__lMain as $n) {
                if (in_array($n, $actualMain, true)) {
                    $__lHitsMain++;
                }
            }
            $__lHitsExtra = ($__lExtra > 0 && !empty($actualExtra) && in_array($__lExtra, $actualExtra, true)) ? 1 : 0;

            $__posSum = 0;
            foreach ($__lMain as $__pos0 => $n) {
                if (in_array($n, $actualMain, true)) {
                    $__posSum += ($__pos0 + 1);
                }
            }
            $__lRankScore = ($__lHitsMain * 10000) - $__posSum + ($__lHitsExtra * 1000);

            if ($__lHitsMain > $mainHitCount
                || ($__lHitsMain === $mainHitCount && $__lHitsExtra > $extraHitCount)
                || ($__lHitsMain === $mainHitCount && $__lHitsExtra === $extraHitCount && $__lRankScore > $rankScore)) {
                $mainHitCount  = $__lHitsMain;
                $extraHitCount = $__lHitsExtra;
                $predMain      = $__lMain;
                $predExtra     = $__lExtra;
                $matchedMain   = array_values(array_intersect($__lMain, $actualMain));
                $rankScore     = $__lRankScore;
            }
        }

        // Collect full scored-run data
        $bestOpps[$lid]['all_scored_runs'][] = [
            'run_id'       => (int)$s->id,
            'game_id'      => (int)$g['game_id'],
            'draw_date'    => (string)$g['draw_date'],
            'source'       => (string)$s->source,
            'hits'         => $mainHitCount,
            'extra_hits'   => (int)$extraHitCount,
            'rank_score'   => $rankScore,
            'pred_main'    => $predMain,
            'pred_extra'   => $predExtra,
            'matched_main' => $matchedMain,
            'actual_main'  => $actualMain,
            'actual_extra' => $actualExtra,
            'has_draw'     => true,
        ];

        $prev      = $bestOpps[$lid]['best_rank'];
        $prevHits  = (int)($prev['hits']      ?? 0);
        $prevExtra = (int)($prev['extra_hits'] ?? 0);
        $prevScore = (int)($prev['score']      ?? -1);
        $prevRunId = (int)($prev['run_id']     ?? 0);

        // Tie-break logic: hits desc -> extra_hits desc -> rank_score desc -> run_id desc
        $isBetter = ($mainHitCount > $prevHits)
            || ($mainHitCount === $prevHits && $extraHitCount > $prevExtra)
            || ($mainHitCount === $prevHits && $extraHitCount === $prevExtra && $rankScore > $prevScore)
            || ($mainHitCount === $prevHits && $extraHitCount === $prevExtra && $rankScore === $prevScore && (int)$s->id > $prevRunId);

        if ($isBetter) {
            $bestOpps[$lid]['best_rank'] = [
                'score'        => $rankScore,
                'draw_date'    => (string)$g['draw_date'],
                'run_id'       => (int)$s->id,
                'source'       => (string)$s->source,
                'hits'         => $mainHitCount,
                'extra_hits'   => (int)$extraHitCount,
                'numbers'      => $predMain,
                'extra_number' => $predExtra,
            ];
        }
    }
}

// -- Post-processing: sort all_scored_runs and promote the #1 run to best_rank --
foreach ($bestOpps as $lid => &$oppRef) {
    if (!empty($oppRef['all_scored_runs'])) {
        usort($oppRef['all_scored_runs'], $__rankCompare);
        $oppWinner = $oppRef['all_scored_runs'][0];
        if (!empty($oppWinner) && !empty($oppWinner['has_draw'])) {
            $oppRef['best_rank'] = [
                'score'        => (int)$oppWinner['rank_score'],
                'draw_date'    => (string)$oppWinner['draw_date'],
                'run_id'       => (int)$oppWinner['run_id'],
                'source'       => (string)$oppWinner['source'],
                'hits'         => (int)$oppWinner['hits'],
                'extra_hits'   => (int)$oppWinner['extra_hits'],
                'numbers'      => (array)$oppWinner['pred_main'],
                'extra_number' => (int)$oppWinner['pred_extra'],
                'has_draw'     => true,
            ];
        }
    }
}
unset($oppRef);

$__winnerRunIds = [];
foreach ($bestOpps as $__lid => $__opp) {
    $__rid = (int)($__opp['best_rank']['run_id'] ?? 0);
    if ($__rid > 0) $__winnerRunIds[] = $__rid;
}
$__winnerRows = [];
if (!empty($__winnerRunIds)) {
    $__wq = $db->getQuery(true)
        ->select('*')
        ->from($db->quoteName('#__user_saved_numbers'))
        ->where($db->quoteName('id')      . ' IN (' . implode(',', $__winnerRunIds) . ')')
        ->where($db->quoteName('user_id') . ' = ' . (int)$user->id);
    $db->setQuery($__wq);
    foreach (($db->loadAssocList() ?: []) as $__wr) {
        $__winnerRows[(int)$__wr['id']] = $__wr;
    }
}

// -- Best Avg Hits per lottery (for Best Settings Snapshot) --
foreach ($bestOpps as $__bavLid => &$__bavOpp) {
    $__bavRuns = $__bavOpp['all_scored_runs'] ?? [];
    if (empty($__bavRuns)) {
        $__bavOpp['best_avg_hits'] = [
            'run_id'          => 0,
            'avg_hits'        => 0.0,
            'scored_runs'     => 0,
            'last_scored_date'=> '',
            'method_label'    => '',
            'display_name'    => 'No scored runs yet',
        ];
        continue;
    }
    // Group scored runs by run_id, accumulate hits and count draws
    $__bavByRun = [];
    foreach ($__bavRuns as $__bavR) {
        $__rid2 = (int)$__bavR['run_id'];
        if (!isset($__bavByRun[$__rid2])) {
            $__bavByRun[$__rid2] = [
                'run_id'  => $__rid2,
                'source'  => (string)$__bavR['source'],
                'total'   => 0,
                'count'   => 0,
                'lastdate'=> '',
            ];
        }
        $__bavByRun[$__rid2]['total'] += (int)$__bavR['hits'];
        $__bavByRun[$__rid2]['count']++;
        $__d = (string)$__bavR['draw_date'];
        if ($__d !== '' && $__d > $__bavByRun[$__rid2]['lastdate']) {
            $__bavByRun[$__rid2]['lastdate'] = $__d;
        }
    }
    // Pick the run_id with the highest average hits (tie-break: higher total, then higher run_id)
    $__bavBest = null;
    foreach ($__bavByRun as $__bavEntry) {
        $__bavAvg = $__bavEntry['count'] > 0 ? ($__bavEntry['total'] / $__bavEntry['count']) : 0;
        if ($__bavBest === null) {
            $__bavBest = $__bavEntry;
            $__bavBest['avg'] = $__bavAvg;
        } else {
            $__bavPrevAvg = $__bavBest['avg'];
            $__isBetter = ($__bavAvg > $__bavPrevAvg)
                || ($__bavAvg === $__bavPrevAvg && $__bavEntry['total'] > $__bavBest['total'])
                || ($__bavAvg === $__bavPrevAvg && $__bavEntry['total'] === $__bavBest['total'] && $__bavEntry['run_id'] > $__bavBest['run_id']);
            if ($__isBetter) {
                $__bavBest = $__bavEntry;
                $__bavBest['avg'] = $__bavAvg;
            }
        }
    }
    // Total scored runs = sum of all per-run draw counts
    $__bavTotalScored = array_sum(array_column($__bavByRun, 'count'));
    // Build human-readable display name
    $__bavSrc   = (string)($__bavBest['source'] ?? '');
    $__bavLabel = $__methodLabels[$__bavSrc] ?? ucwords(str_replace('_', ' ', $__bavSrc));
    // Check if the winning run row has a template name or run mode
    $__bavWinnerRow = $__winnerRows[$__bavBest['run_id']] ?? null;
    if ($__bavWinnerRow !== null) {
        $__bavDisplayName = buildBestSettingsSummary((array)$__bavWinnerRow, $__methodLabels);
    } else {
        $__bavDisplayName = $__bavLabel;
    }
    $__bavOpp['best_avg_hits'] = [
        'run_id'          => (int)$__bavBest['run_id'],
        'avg_hits'        => round((float)$__bavBest['avg'], 2),
        'scored_runs'     => $__bavTotalScored,
        'last_scored_date'=> (string)($__bavBest['lastdate'] ?? ''),
        'method_label'    => $__bavLabel,
        'display_name'    => $__bavDisplayName,
    ];
}
unset($__bavOpp);

// -- CRR: run_id ? savedSet lookup for run mode and timestamp --
$__crrRunMap = [];
foreach ($savedSets as $__crrS) {
    $__crrRunMap[(int)$__crrS->id] = $__crrS;
}

// -- Method stats for Trends & Evidence section (unchanged) --
$__methodStats = [];
$__totalScoredRuns = 0;
foreach ($savedSets as $__ss) {
    $__src = (string)$__ss->source;
    if (!isset($__methodStats[$__src])) {
        $__methodStats[$__src] = [
            'runs'            => 0,
            'runs_all'        => 0,
            'best_hits'       => 0,
            'best_extra_hits' => 0,
            'total_hits'      => 0,
            'hits_2plus_count'=> 0,
            'last_date'       => '',
            'earliest_rank'   => PHP_INT_MAX,
            'latest_rank'     => 0,
        ];
    }
    $__methodStats[$__src]['runs_all']++;
    // record last_date
    $tmpDate = '';
    if (!empty($__ss->generated_at)) {
        $tmpDate = (string)$__ss->generated_at;
    } elseif (!empty($__ss->date_saved)) {
        $tmpDate = (string)$__ss->date_saved;
    }
    if ($tmpDate !== '') {
        $tmpTs  = strtotime($tmpDate);
        $currTs = $__methodStats[$__src]['last_date'] !== '' ? strtotime($__methodStats[$__src]['last_date']) : 0;
        if ($tmpTs !== false && $tmpTs > $currTs) {
            $__methodStats[$__src]['last_date'] = date('Y-m-d', $tmpTs);
        }
    }
}
foreach ($bestOpps as $__lid => $__opp) {
    foreach ($__opp['all_scored_runs'] as $__sr) {
        $__src = (string)$__sr['source'];
        if (!isset($__methodStats[$__src])) {
            $__methodStats[$__src] = [
                'runs'            => 0,
                'runs_all'        => 0,
                'best_hits'       => 0,
                'best_extra_hits' => 0,
                'total_hits'      => 0,
                'hits_2plus_count'=> 0,
                'last_date'       => '',
                'earliest_rank'   => PHP_INT_MAX,
                'latest_rank'     => 0,
            ];
        }
        $__methodStats[$__src]['runs']++;
        $__totalScoredRuns++;
        $h  = (int)$__sr['hits'];
        $eh = (int)($__sr['extra_hits'] ?? 0);
        if ($h > $__methodStats[$__src]['best_hits']
            || ($h === $__methodStats[$__src]['best_hits'] && $eh > $__methodStats[$__src]['best_extra_hits'])) {
            $__methodStats[$__src]['best_hits']       = $h;
            $__methodStats[$__src]['best_extra_hits'] = $eh;
        }
        $__methodStats[$__src]['total_hits'] += $h;
        if ($h >= 2) {
            $__methodStats[$__src]['hits_2plus_count']++;
        }
        // Track rank positions of matched numbers (mirrors Rank Accuracy Insight math)
        foreach (($__sr['matched_main'] ?? []) as $__mn) {
            $__rp = array_search($__mn, $__sr['pred_main'] ?? [], true);
            if ($__rp !== false) {
                $__rk = (int)$__rp + 1;
                if ($__rk < $__methodStats[$__src]['earliest_rank']) {
                    $__methodStats[$__src]['earliest_rank'] = $__rk;
                }
                if ($__rk > $__methodStats[$__src]['latest_rank']) {
                    $__methodStats[$__src]['latest_rank'] = $__rk;
                }
            }
        }
    }
}

// -- Global confidence level for Trends & Evidence section (unchanged) --
$__totalRuns        = count($savedSets ?: []);
$__globalConfidence = 'Low';
$__globalGuidance   = 'Save a few more analyses to improve signal clarity.';
if ($__totalRuns >= 6 && !empty($__methodStats)) {
    $__stable = false;
    foreach ($__methodStats as $__ms) {
        if ($__ms['runs'] > 0 && ($__ms['hits_2plus_count'] / $__ms['runs']) >= 0.3) {
            $__stable = true; break;
        }
    }
    if ($__stable) {
        $__globalConfidence = 'Strong';
        $__globalGuidance   = 'Consistent pattern detected. Consider repeating the leading approach.';
    } else {
        $__globalConfidence = 'Building';
        $__globalGuidance   = 'Signal is forming. Continue controlled testing.';
    }
} elseif ($__totalRuns >= 3) {
    $__globalConfidence = 'Building';
    $__globalGuidance   = 'Signal is forming. Continue controlled testing.';
}

// -- Admin diagnostic and method label definitions follow as in the original code --
?>
<!-- ==================================================
     CSS Styles (Sorcerer [[style]] block)   'lottery_id
     ================================================== -->
[[style]]
/* ----------------------------------------------------------------------
   Design System Variables
---------------------------------------------------------------------- */
:root {
  /* SKAI Brand Palette */
  --skai-blue: #1C66FF;
  --skai-navy: #0A1A33;
  --skai-sky-gray: #EFEFF5;
  --skai-slate: #7F8DAA;
  --skai-success: #20C997;
  --skai-amber: #F5A623;

  /* SKAI Design System */
  --skai-surface: #ffffff;
  --skai-surface-subtle: #f8fafc;
  --skai-border-subtle: #e2e8f0;
  --skai-border: #cbd5e1;
  --skai-text-primary: #0A1A33;
  --skai-text-secondary: #475569;
  --skai-text-muted: #7F8DAA;
  --skai-heading: #0A1A33;
  --skai-accent: #1C66FF;
  --skai-accent-subtle: rgba(28,102,255,0.12);
  --skai-shadow-soft: 0 1px 3px 0 rgba(10,26,51,0.06), 0 1px 2px 0 rgba(10,26,51,0.04);
  --skai-shadow-medium: 0 4px 14px rgba(10,26,51,0.10), 0 2px 4px rgba(10,26,51,0.06);

  /* Spacing system (8px base) */
  --space-xs: 0.25rem;
  --space-s: 0.5rem;
  --space-m: 1rem;
  --space-l: 1.5rem;
  --space-xl: 2rem;

  /* Typography scale */
  --font-base-size: 16px;
  --line-height-multiplier: 1.5;
  --line-height: calc(var(--font-base-size) * var(--line-height-multiplier));
}

/* ----------------------------------------------------------------------
   Base & Typography
---------------------------------------------------------------------- */
#sp-main-body { padding-top: 5px !important; }

body{
  background: var(--skai-surface-subtle);
  color: var(--skai-text-primary);
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
  line-height: 1.6;
  margin: 0;
  padding: 0;
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
}
p, ul, li {
  font-size: 1rem;
  margin: 0 0 1rem 0;
  color: var(--skai-text-secondary);
}
h2.title, h3.title {
  font-weight: 600;
  color: var(--skai-heading);
  margin: 0 0 1rem 0;
  letter-spacing: -0.02em;
}
h2.title { font-size: 1.875rem; }
h3.title { font-size: 1.5rem; }
.lottery-title {
  font-size: 1.125rem;
  font-weight: 600;
  color: var(--skai-heading);
  letter-spacing: -0.01em;
}

/* ----------------------------------------------------------------------
   Cards & Containers
---------------------------------------------------------------------- */
.card {
  background: var(--skai-surface);
  border: 1px solid var(--skai-border-subtle);
  border-radius: 12px;
  box-shadow: var(--skai-shadow-soft);
  padding: 1.5rem;
  margin-bottom: 1.5rem;
  transition: box-shadow 0.2s ease, border-color 0.2s ease;
}
.card:hover {
  box-shadow: var(--skai-shadow-medium);
  border-color: var(--skai-border);
}
.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding-bottom: 0.75rem;
  border-bottom: 1px solid var(--skai-border-subtle);
  margin-bottom: 1rem;
}
.card-header .title {
  font-size: 1rem;
  font-weight: 600;
  color: var(--skai-heading);
  letter-spacing: -0.01em;
}
.smart-card{
  border-left:6px solid #f1c40f;
  background:#fffef7;
}

/* Banner */
.banner{
  background:linear-gradient(135deg,#0A1A33 0%,#1C66FF 100%);
  color:#f9fafb;
  padding:1.15rem 1.5rem;
  border-radius:12px;
  box-shadow:0 14px 30px rgba(15,23,42,0.22);
  margin-bottom:1.75rem;
  position:relative;
  overflow:hidden;
}

/* subtle gloss overlay */
.banner::before{
  content:"";
  position:absolute;
  inset:0;
  background:radial-gradient(circle at 0% 0%,rgba(255,255,255,0.22) 0,rgba(255,255,255,0) 45%);
  opacity:.9;
  pointer-events:none;
}

.banner .card-header{
  border-bottom:none;
  padding-bottom:0;
  margin-bottom:0;
}

.banner .title{
  color:#f9fafb!important;
  font-size:1.85rem;
  letter-spacing:.01em;
  margin-bottom:.25rem;
}

.hero-text{
  position:relative;
  z-index:1;
  max-width:640px;
}

.hero-subtitle{
  font-size:.98rem;
  color:#e5e9f5;
  max-width:34rem;
  margin:0;
  line-height:1.55;
}

/* Mobile tightening */
@media (max-width:640px){
  .banner{
    padding:0.95rem 1rem;
    border-radius:10px;
    margin-bottom:1.5rem;
  }
  .banner .title{
    font-size:1.5rem;
  }
  .hero-subtitle{
    font-size:.92rem;
  }
}


/* Lottery logos */
.lotto-logo {
  display:inline-block;
  height:120px;
  width:auto;
  vertical-align:middle;
  border-radius:4px;
  background:#fff;
  border:1px solid #e5e8ec;
  padding:4px;
  box-shadow:0 1px 3px rgba(0,0,0,.06);
  object-fit:contain;
}
.lotto-logo.fallback {
  display:inline-flex;
  align-items:center;
  justify-content:center;
  height:120px; min-width:38px;
  font-weight:800; font-size:14px; color:#2c3e50;
  background:linear-gradient(180deg,#fdfdfd,#f2f4f7);
}
/* Small logo for Best Opportunities panel */
.lotto-logo--sm {
  height:32px;
  width:auto;
  border-radius:4px;
  background:#fff;
  border:1px solid #e5e8ec;
  padding:2px;
  box-shadow:0 1px 3px rgba(0,0,0,.06);
  object-fit:contain;
  vertical-align:middle;
}
.lotto-logo--sm.fallback {
  display:inline-flex;
  align-items:center;
  justify-content:center;
  height:32px;
  min-width:32px;
  font-weight:800;
  font-size:11px;
  color:#2c3e50;
  background:linear-gradient(180deg,#fdfdfd,#f2f4f7);
  border-radius:4px;
  border:1px solid #e5e8ec;
  padding:2px 5px;
}
/* Best Rank Strength card highlight */
.prediction-card.skai-best-rank-highlight {
  outline: 3px solid #1C66FF;
  outline-offset: 2px;
  box-shadow: 0 0 0 5px rgba(28,102,255,0.18), 0 2px 12px rgba(28,102,255,0.12);
  position: relative;
  z-index: 1;
}
.skai-best-rank-ribbon {
  display: inline-block;
  background: #1C66FF;
  color: #ffffff;
  font-size: 0.68rem;
  font-weight: 800;
  padding: 2px 8px;
  border-radius: 4px;
  letter-spacing: 0.03em;
  margin-left: 6px;
  vertical-align: middle;
  white-space: nowrap;
}
.lotto-title-wrap {
  display:flex; align-items:center; gap:.6rem; flex-wrap:wrap;
}

/* ----------------------------------------------------------------------
   Section Wrappers
---------------------------------------------------------------------- */
.saved-cards-grid{ display:grid; grid-template-columns:repeat(auto-fit,minmax(300px,1fr)); gap:1.5rem; }

/* Legend */
.legend-card{ padding:10px; border:none; box-shadow:none; margin-bottom:1rem; }
.legend-card .card-header{ background:transparent; border-bottom:none; padding:.5rem 1rem; }
.legend-container{ display:flex; gap:1rem; padding:.5rem 1rem; }
.legend-box{ position:relative; padding-left:1.6rem; font-weight:600; }
.legend-box.legend-skip::before,
.legend-box.legend-ai::before,
.legend-box.legend-mcmc::before,
.legend-box.legend-heatmap::before{
  content:""; position:absolute; left:0; top:50%; width:.8rem; height:.8rem; transform:translateY(-50%); border-radius:2px;
}
.legend-box.legend-skip::before{ background:#1C66FF; }
.legend-box.legend-ai::before{ background:#22c55e; }
.legend-box.legend-mcmc::before{ background:#a855f7; }
.legend-box.legend-heatmap::before{ background:#f97316; }

/* Header layout for each prediction card */
.prediction-card .card-header {
  display: grid;
  grid-template-columns: 1fr auto;  /* left grows, right hugs content */
  gap: .5rem 1rem;
  align-items: start;
}

.prediction-card .header-left {
  display: grid;
  grid-template-rows: auto auto;    /* row 1: badges/label, row 2: compare */
  gap: .35rem;
  min-width: 0;
}

.prediction-card .header-badges {
  display: flex;
  align-items: center;
  gap: .75rem;
  flex-wrap: wrap;                  /* wraps nicely on smaller widths */
}

.prediction-card .header-compare {
  display: flex;
  align-items: center;
  gap: .35rem;
  font-size: .85rem;
  color: #333;
}

/* keep the gear fixed at top-right */
.prediction-card .settings-toggle {
  grid-column: 2;
  grid-row: 1 / span 2;
  align-self: start;
  white-space: nowrap;
}

/* Small screens: stack settings under left column, right-aligned */
@media (max-width: 520px) {
  .prediction-card .card-header {
    grid-template-columns: 1fr;
  }
  .prediction-card .settings-toggle {
    grid-column: 1;
    grid-row: auto;
    justify-self: end;
    margin-top: .25rem;
  }
}

/* ----------------------------------------------------------------------
   Buttons & Inputs
---------------------------------------------------------------------- */
.btn-primary {
  background: var(--skai-accent);
  color: #fff;
  padding: 0.625rem 1.25rem;
  border: none;
  border-radius: 8px;
  text-decoration: none;
  font-weight: 500;
  font-size: 0.9375rem;
  display: inline-block;
  cursor: pointer;
  transition: background 0.2s ease, transform 0.1s ease;
  letter-spacing: -0.01em;
}
.btn-primary:hover {
  background: #1549e0;
  transform: translateY(-1px);
}
.btn-primary:active {
  transform: translateY(0);
}
.btn-secondary {
  background: var(--skai-surface);
  color: var(--skai-text-primary);
  border: 1px solid var(--skai-border);
  padding: 0.625rem 1.25rem;
  border-radius: 8px;
  font-weight: 500;
  font-size: 0.9375rem;
  cursor: pointer;
  transition: background 0.2s ease, border-color 0.2s ease;
  letter-spacing: -0.01em;
}
.btn-secondary:hover {
  background: var(--skai-surface-subtle);
  border-color: var(--skai-border);
}
.btn-insight {
  font-size: 0.9rem;
  display: inline-block;
  padding: 0.5rem 1rem;
  margin: 0.5rem 0;
}
.filter-input {
  width: 100%;
  padding: 0.625rem 0.875rem;
  border: 1px solid var(--skai-border-subtle);
  border-radius: 8px;
  font-size: 0.9375rem;
  margin-bottom: 1rem;
  transition: border-color 0.2s ease, box-shadow 0.2s ease;
}
.filter-input:focus {
  outline: none;
  border-color: var(--skai-accent);
  box-shadow: 0 0 0 3px var(--skai-accent-subtle);
}

/* Badges */
.source-badge {
  background: var(--skai-text-secondary);
  color: #fff;
  padding: 0.3rem 0.75rem;
  border-radius: 6px;
  font-size: 0.8125rem;
  font-weight: 500;
  letter-spacing: 0.01em;
  display: inline-block;
  margin-bottom: 0.5rem;
  cursor: help;
  transition: background 0.2s ease;
}
.source-badge:hover {
  background: var(--skai-text-primary);
}

/* Method color themes - Subtle, calm approach */
.prediction-card.skip-hit { background: #eff6ff; border-left: 3px solid #1C66FF; }
.prediction-card.ai-prediction { background: #f0fdf4; border-left: 3px solid #22c55e; }
.prediction-card.mcmc-prediction { background: #faf5ff; border-left: 3px solid #a855f7; }
.prediction-card.skai-prediction { background: #ecfeff; border-left: 3px solid #06b6d4; }
.prediction-card.skip-hit .source-badge { background: #1C66FF; color:#ffffff; border-color:#1554d4; }
.prediction-card.ai-prediction .source-badge { background: #22c55e; color:#ffffff; border-color:#16a34a; }
.prediction-card.mcmc-prediction .source-badge { background: #a855f7; color:#ffffff; border-color:#7c3aed; }
.prediction-card.skai-prediction .source-badge { background: #06b6d4; color:#ffffff; border-color:#0891b2; }

/* Heatmap theme */
.prediction-card.heatmap { background: #fff7ed; border-left: 3px solid #f97316; }
.prediction-card.heatmap .source-badge { background: #f97316; color:#ffffff; border-color:#ea580c; }

/* ----------------------------------------------------------------------
   Number pills
---------------------------------------------------------------------- */
/* ----------------------------------------------------------------------
   Number pills (fixed so extra matches are GREEN like mains)
---------------------------------------------------------------------- */
.drawn-pill, .match-pill, .no-match, .pred-pill {
  padding: 1px 7px;
  margin: 2px;
  border-radius: 14px;
  font-size: 12px;
  min-width: 2rem;
  text-align: center;
  font-weight: bold;
  display: inline-block;
}

/* Hits = green */
.match-pill { background:#27ae60; color:#fff; box-shadow:0 0 5px rgba(39,174,96,.4); }
/* Keep extra hits green as well */
.match-pill.extra { background:#27ae60; color:#fff; box-shadow:0 0 5px rgba(39,174,96,.4); }

/* Misses = gray; extra misses = light blue */
.no-match { background:#bdc3c7; color:#060607; opacity:.7; }
.no-match.extra { background:#aed6f1; color:#000; font-weight:700; }

/* Pending predictions (draw not yet recorded) = neutral blue-gray */
.pred-pill { background:#d1d5db; color:#374151; opacity:.85; }
.pred-pill.extra { background:#bfdbfe; color:#1e3a5f; font-weight:700; }

/* Drawn numbers (mains and extras) = green */
.drawn-pill, .drawn-pill.extra {
  background:#27ae60;
  color:#fff;
  box-shadow: inset 0 0 0 1px rgba(255,255,255,.6);
}

/* When JS marks "overlap & drawn" it uses .drawn-match; keep that green too */
.drawn-match {
  background:#27ae60;
  color:#fff;
  box-shadow: inset 0 0 0 1px rgba(255,255,255,.6);
}

/* Common-across card */
.common-card{ display:none; margin-bottom:1.5rem; }
.common-card .common-numbers{ display:flex; flex-wrap:wrap; gap:.5rem; padding:1rem; }
.common-count{ color:#1f618d; font-weight:600; font-size:1.05rem; }

/* ----------------------------------------------------------------------
   Prediction Grid & Cards
---------------------------------------------------------------------- */
.prediction-card,
.prediction-placeholder{
  border-radius:10px;
  padding:5px;
  position:relative;
  transition:box-shadow .2s ease;
  display:flex;
  flex-direction:column;
}

/* Default prediction cards keep their existing theme colors */
.prediction-card{
  background:#fafafa;
  border:1px solid #ccc;
}

.prediction-card:hover{
  box-shadow:0 4px 14px rgba(0,0,0,.1);
}

/* Empty-state placeholder uses SKAI surface + dashed border */
.prediction-placeholder{
  background:var(--skai-surface);
  border:2px dashed var(--skai-border-subtle);
  color:#555;
  text-align:center;
}
.lottery-header{ margin-bottom:1.5rem; }

/* Responsive columns:
   - = 1100px: 3 across
   - 700-1099px: 2 across
   - < 700px: 1 across  */
.predictions-row{
  display: grid;
  gap: 1.5rem;
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
}

/* cap at 3 columns on wide screens */

@media (min-width: 1100px){
  .predictions-row{ grid-template-columns: repeat(3, 1fr); }
}

/* each column stacks its multiple run-cards neatly */
.prediction-column{
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
  min-width: 0;      /* prevents grid overflow */
}

/* Placeholder look */
.prediction-placeholder{ background:#fcfcfc; border:2px dashed #d0d0d0; border-radius:8px; box-shadow:inset 0 0 20px rgba(0,0,0,.02); }
.prediction-placeholder .title{ font-size:1.6rem; color:#2c3e50; margin-bottom:1rem; }
.prediction-placeholder p{ font-size:1rem; color:#555; margin-bottom:1.5rem; }

/* Toggles & comparison controls */

.settings-toggle{
  cursor: pointer;
  font-size: 1rem;
  color: #666;
  transition: transform .2s ease;
}

/* Default = collapsed (no FOUC). JS will add .open when needed */
.settings-panel { display: none !important; }
.settings-panel.open { display: block !important; }

/* Buttons */
.btn.btn-danger, .btn-danger{
  background:#c0392b; color:#fff; padding:.25rem .6rem; font-size:.8rem; border-radius:4px; font-weight:600; border:none; cursor:pointer; display:inline-block; text-align:center; line-height:1.2;
}
.btn.btn-danger:hover, .btn-danger:hover{ background:#a93226; }

/* Settings grid in each card */
.settings-grid{ display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:.5rem; }
.setting-row{
  font-size:.85rem; background:#f9f9f9; border:1px solid #e0e0e0; border-radius:4px;
  padding:.3rem .6rem; display:flex; justify-content:space-between; align-items:center;
}

/* Lottery group as a card */
.lottery-group.card{
  background:#f9f9f9; border:1px solid #e0e0e5; border-radius:8px; overflow:hidden; margin-bottom:2rem;
}
.lottery-group.card .lottery-header{
  background:#e6f2ff; padding:1rem 1.5rem; border-bottom:1px solid #e0e0e5; border-top-left-radius:8px; border-top-right-radius:8px;
}
.lottery-group.card .predictions-row{
  padding:1rem 1.5rem 1.5rem; background:#fff; border-radius:0 0 8px 8px;
}

/* Lottery collapse functionality */
.lottery-collapsible-content {
  display: block;
}
.lottery-group.collapsed .lottery-collapsible-content {
  display: none;
}
.lottery-group.collapsed .lottery-header {
  border-bottom: none;
  border-radius: 8px;
}
.lottery-collapse-toggle {
  cursor: pointer;
}
.lottery-group.collapsed .lottery-collapse-toggle {
  color: #7F8DAA;
}

/* Collapse helpers */
:root{ --font-base-size:16px; --line-height-multiplier:1.5; --space-s:8px; --color-primary:#1C66FF; --line-height:calc(var(--font-base-size)*var(--line-height-multiplier)); }
.card-body .content.collapsible{ display:none!important; }
.card-body .content.collapsible:not(.collapsed){ display:block!important; }
.section-label{ font-weight:bold; display:block; margin:10px 0 5px; }

/* Responsive tweaks */
@media (max-width:640px){
  /* do NOT force settings-panel open on mobile */
  /* keep it controlled by the .open class */
}

@media (max-width:480px){
  .drawn-pill, .match-pill, .no-match{ font-size:.85rem; padding:.3rem .5rem; }
}


/* Narrative chips & badges */
.narrative .section-h { font-weight:700; margin:.4rem 0 .25rem; }
.narrative .subtle    { color:#4a5a70; }
.narrative .s-row     { margin:.3rem 0; }
.narrative .bullet    { margin:.15rem 0; }

/* chip container (shared) */
.narrative .chips { display:flex; flex-wrap:wrap; gap:.5rem; margin:.35rem 0 .5rem; padding-bottom:12px; }

/* legacy "chip" (kept for any text chips you still render) */
.narrative .chip {
  display:inline-flex; align-items:center; gap:.35rem;
  padding:.2rem .5rem; border:1px solid #dfe6f2; border-radius:14px;
  font-size:.9rem; background:#f7fafd; color:#1f2a44;
}
.narrative .chip .tags { display:inline-flex; gap:.15rem; }
.narrative .tag {
  font-size:.7rem; font-weight:700; line-height:1;
  padding:.1rem .3rem; border-radius:10px; background:#e9eef7; color:#31405a;
}
.narrative .tag.hit { background:#27ae60; color:#fff; }
.narrative .tag.ovl { background:#1f618d; color:#fff; }
.narrative .tag.ai  { background:#22c55e; color:#fff; }
.narrative .tag.sh  { background:#1C66FF; color:#fff; }
.narrative .tag.mm  { background:#a855f7; color:#fff; }
.narrative .tag.hm  { background:#f97316; color:#fff; }

/* Lottery-ball styling */
.narrative .ball {
  position: relative;
  width: 42px; height: 42px; border-radius: 50%;
  display: inline-flex; align-items: center; justify-content: center;
  font-weight: 800; font-size: 1rem; color: #0b1320;
  background: radial-gradient(circle at 35% 30%, #ffffff 0%, #f4f4f4 45%, #e1e1e1 100%);
  box-shadow: 0 2px 0 rgba(0,0,0,.15), inset 0 -2px 4px rgba(0,0,0,.08);
  border: 1px solid #cfcfcf;
}

/* badge rail */
.narrative .ball-tags {
  position: absolute; bottom: -6px; left: 50%; transform: translateX(-50%);
  display: inline-flex; gap: 3px;
}
.narrative .btag {
  font-size: .75rem; line-height: 1; font-weight: 800;
  padding: 2px 6px; border-radius: 999px;
  border: none; color: #fff; box-shadow: 0 1px 0 rgba(0,0,0,.25);
  background: #2c3e50;   /* default high-contrast */
}
.narrative .btag.hit { background:#2ecc71; }
.narrative .btag.ovl { background:#1f618d; }
.narrative .btag.ai  { background:#22c55e; }
.narrative .btag.sh  { background:#1C66FF; }
.narrative .btag.mm  { background:#a855f7; }
.narrative .btag.hm  { background:#f97316; }

/* Tap-friendly, always clickable, sits on top of any overlaps */
.settings-toggle{
  appearance:none;
  background:none;
  border:none;
  padding:.35rem .5rem;
  font:inherit;
  cursor:pointer;
  border-radius:.25rem;
  -webkit-tap-highlight-color: rgba(0,0,0,0);
  pointer-events:auto;
  position:relative;
  z-index:2; /* keep above header contents */
}
.settings-toggle:focus{
  outline:2px solid #1C66FF;
  outline-offset:2px;
}


/* Give the legend card more breathing room */
.legend-card{
  padding:0.75rem 1.25rem 0.75rem;   /* top / sides / bottom */
}

/* Slight extra space between title and chips */
.legend-card .card-header{
  margin-bottom:0.4rem;
}

/* Lay out the legend items nicely */
.legend-card .legend-container{
  display:flex;
  flex-wrap:wrap;
  align-items:center;
  gap:0.75rem 1.5rem;
}

/* Base pill + colored square */
.legend-card .legend-box{
  position:relative;
  padding-left:1.15rem;   /* room for the colored square */
  font-size:0.9rem;
}

.legend-card .legend-box::before{
  content:'';
  position:absolute;
  left:0;
  top:50%;
  transform:translateY(-50%);
  width:10px;
  height:10px;
  border-radius:3px;
}

/* Per-analysis colors (including SKAI) */
.legend-card .legend-box.legend-skip::before{   background-color:#1C66FF; } /* blue */
.legend-card .legend-box.legend-ai::before{     background-color:#22c55e; } /* green */
.legend-card .legend-box.legend-skai::before{   background-color:#06b6d4; } /* teal SKAI */
.legend-card .legend-box.legend-mcmc::before{   background-color:#a855f7; } /* purple */
.legend-card .legend-box.legend-heatmap::before{background-color:#f97316; } /* orange */

[[/style]]

<?php if (empty($groups)): ?>
  [[div class="skai-card prediction-placeholder" style="margin-bottom:2rem; text-align:center; padding:2rem;"]]

    [[h3 class="skai-card__title"]]Start Your Strategy[[/h3]]

      [[p style="font-size:1.05rem; color:#444; margin-bottom:1rem;"]]
        You haven't saved any predictions yet.
        [[br]]
        To get started, run a prediction (AI, MCMC, or Skip-Hit), and save your results here.
      [[/p]]

      [[a href="/#ai-tools" class="btn-primary"]]
        Run a Prediction
      [[/a]]

  [[/div]]
<?php endif; ?>

<?php if (! empty($groups) ): ?>

<?php if (!empty($bestOpps) || !empty($__methodStats)): ?>
<!-- ==================================================
     Trends & Evidence Section
================================================== -->
[[div class="skai-trends" id="skai-trends"]]
  [[h2 class="skai-trends__heading"]]Trends &amp; Evidence[[/h2]]

  [[div class="skai-trends__confidence"]]
    [[span class="skai-trends__conf-label"]]Confidence:[[/span]]
    [[span class="skai-trends__conf-value skai-trends__conf-<?php echo strtolower($__globalConfidence); ?>"]]<?php echo htmlspecialchars($__globalConfidence, ENT_QUOTES); ?>[[/span]]
    [[span class="skai-trends__conf-evidence"]]<?php echo (int)$__totalRuns; ?> saved <?php echo $__totalRuns === 1 ? 'analysis' : 'analyses'; ?>[[/span]]
  [[/div]]
  [[p class="skai-trends__guidance"]]<?php echo htmlspecialchars($__globalGuidance, ENT_QUOTES); ?>[[/p]]

  <?php if (!empty($__methodStats)): ?>
  [[div class="skai-trends__chart-wrap"]]
    [[div class="skai-trends__chart-title"]]Method Comparison[[/div]]
[[div class="skai-trends__chart-sub"]]
Total hits (all scored runs) per method and how often it got 2+ matches (out of all runs)
[[/div]]
    [[canvas id="skai-method-chart" class="skai-trends__canvas"
             aria-label="Method comparison chart"
             role="img"
             width="600" height="220"]][[/canvas]]
    [[div id="skai-method-chart-fallback" class="skai-trends__chart-fallback" style="display:none;"]]
      <?php foreach ($__methodStats as $__mSrc => $__mStat): ?>
        <?php
          $__mLabel    = $__methodLabels[$__mSrc] ?? ucwords(str_replace('_', ' ', $__mSrc));
          $__mRunsAll  = max($__mStat['runs_all'] ?? 0, $__mStat['runs']);
          $__mRate     = $__mStat['runs'] > 0 ? round($__mStat['hits_2plus_count'] / $__mStat['runs'] * 100) : 0;
        ?>
        [[div class="skai-trends__fallback-row"]]
          [[span class="skai-trends__fallback-name"]]<?php echo htmlspecialchars($__mLabel, ENT_QUOTES); ?>[[/span]]
<?php
    $__totalHitsFb  = (int)($__mStat['total_hits'] ?? 0);
    $__earliestFb   = (int)($__mStat['earliest_rank'] ?? PHP_INT_MAX);
    $__latestFb     = (int)($__mStat['latest_rank']   ?? 0);
    $__runsAll      = max((int)($__mStat['runs_all'] ?? 0), (int)($__mStat['runs'] ?? 0));
    $__rate         = ($__mStat['runs'] > 0 ? round(($__mStat['hits_2plus_count'] / $__mStat['runs']) * 100) : 0);
    $__rangeFb      = ($__earliestFb !== PHP_INT_MAX && $__latestFb > 0)
        ? ('#' . $__earliestFb . '-#' . $__latestFb)
        : '-';
    $__lastDate     = (string)($__mStat['last_date'] ?? '');
?>
[[span class="skai-trends__fallback-peak"]]Total hits: <?php echo $__totalHitsFb; ?>, range <?php echo $__rangeFb; ?>[[/span]]
[[span class="skai-trends__fallback-rate"]]
2+ match rate: <?php echo $__rate; ?>% (<?php echo (int)$__mStat['hits_2plus_count']; ?> of <?php echo $__runsAll; ?> run<?php echo $__runsAll !== 1 ? 's' : ''; ?>)
<?php if ($__lastDate !== ''): ?>
 &nbsp;-&nbsp;last run <?php echo htmlspecialchars(date('M j, Y', strtotime($__lastDate)), ENT_QUOTES); ?>
<?php endif; ?>
[[/span]]
        [[/div]]
      <?php endforeach; ?>
    [[/div]]
    [[script]]
    (function(){
      var canvas = document.getElementById('skai-method-chart');
      var fallback = document.getElementById('skai-method-chart-fallback');
      if (!canvas || !canvas.getContext) {
        if (fallback) fallback.style.display = '';
        return;
      }
      var ctx = canvas.getContext('2d');
      if (!ctx) { if (fallback) fallback.style.display = ''; return; }

      // Data from PHP
      var methods = [<?php
        $__chartParts = [];
        foreach ($__methodStats as $__mSrc => $__mStat) {
            $__mLabel        = $__methodLabels[$__mSrc] ?? ucwords(str_replace('_', ' ', $__mSrc));
            $__mRunsAll      = max($__mStat['runs_all'] ?? 0, $__mStat['runs']);
            $__mRate         = $__mStat['runs'] > 0 ? round($__mStat['hits_2plus_count'] / $__mStat['runs'] * 100) : 0;
            $__mEarliestRank = (int)($__mStat['earliest_rank'] ?? PHP_INT_MAX);
            $__mLatestRank   = (int)($__mStat['latest_rank']   ?? 0);
$__chartParts[] = '{"label":' . json_encode($__mLabel)
    . ',"peak":'         . (int)($__mStat['total_hits'] ?? 0)
    . ',"peakExtra":'    . (int)($__mStat['best_extra_hits'] ?? 0)
    . ',"rate":'         . (int)$__mRate
    . ',"runs":'         . (int)$__mRunsAll
    . ',"hits2plus":'    . (int)$__mStat['hits_2plus_count']
    . ',"earliestRank":' . ($__mEarliestRank === PHP_INT_MAX ? 'null' : $__mEarliestRank)
    . ',"latestRank":'   . ($__mLatestRank > 0 ? $__mLatestRank : 'null')
    . ',"lastDate":'     . json_encode((string)($__mStat['last_date'] ?? '')) . '}';        }
        echo implode(',', $__chartParts);
      ?>];

      if (!methods.length) { if (fallback) fallback.style.display = ''; return; }

      // Responsive: set canvas pixel size to display size
      var dpr = window.devicePixelRatio || 1;
      var w   = canvas.offsetWidth || 600;
      var h   = 220;
      canvas.width  = w * dpr;
      canvas.height = h * dpr;
      ctx.scale(dpr, dpr);

      var padL = 16, padR = 16, padT = 18, padB = 54;
      var chartW = w - padL - padR;
      var chartH = h - padT - padB;
      var n = methods.length;
      var barGroup = Math.floor(chartW / n);
      var barW     = Math.max(8, Math.floor(barGroup * 0.3));
      var gap      = Math.max(4, Math.floor(barGroup * 0.06));

      // Max values for scale
      var maxPeak = 0;
      for (var i = 0; i < methods.length; i++) { if (methods[i].peak > maxPeak) maxPeak = methods[i].peak; }
      maxPeak = Math.max(maxPeak, 1);

      // Grid lines
      ctx.strokeStyle = 'rgba(10,26,51,0.07)';
      ctx.lineWidth = 1;
      for (var gl = 0; gl <= 4; gl++) {
        var gy = padT + chartH - (gl / 4) * chartH;
        ctx.beginPath(); ctx.moveTo(padL, gy); ctx.lineTo(padL + chartW, gy); ctx.stroke();
      }

      // Bars
      for (var i = 0; i < methods.length; i++) {
        var m   = methods[i];
        var gx  = padL + i * barGroup + Math.floor((barGroup - (barW * 2 + gap)) / 2);

        // Peak bar (navy blue)
        var ph = Math.max(3, (m.peak / maxPeak) * chartH);
        ctx.fillStyle = '#1C66FF';
        ctx.beginPath();
        ctx.roundRect ? ctx.roundRect(gx, padT + chartH - ph, barW, ph, 3)
                      : ctx.rect(gx, padT + chartH - ph, barW, ph);
                ctx.fill();
        ctx.closePath();

        // Consistency bar (teal/green)
        var rh = Math.max(m.rate > 0 ? 3 : 0, (m.rate / 100) * chartH);
        ctx.fillStyle = '#20C997';
        ctx.beginPath();
        ctx.roundRect ? ctx.roundRect(gx + barW + gap, padT + chartH - rh, barW, rh, 3)
                      : ctx.rect(gx + barW + gap, padT + chartH - rh, barW, rh);
                ctx.fill();
        ctx.closePath();

// Value labels
ctx.fillStyle = '#0A1A33';
ctx.font = 'bold 10px system-ui,sans-serif';
ctx.textAlign = 'center';
// Show total hits (same math as Rank Accuracy Insight)
var labelPeak = m.peak;
if (m.peak > 0) {
  ctx.fillText(String(labelPeak), gx + barW / 2, padT + chartH - ph - 3);
}
if (m.rate > 0) {
  ctx.fillText(m.rate + '%', gx + barW + gap + barW / 2, padT + chartH - rh - 3);
}
        // X-axis label (truncated) + run count
        var lbl = m.label.length > 12 ? m.label.slice(0, 11) + '.' : m.label;
        ctx.fillStyle = '#475569';
        ctx.font = '10px system-ui,sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText(lbl, gx + barW + gap / 2, padT + chartH + 13);
        if (m.runs > 0) {
          ctx.fillStyle = '#94a3b8';
          ctx.font = '9px system-ui,sans-serif';
          ctx.fillText(m.runs + ' run' + (m.runs !== 1 ? 's' : ''), gx + barW + gap / 2, padT + chartH + 24);
        }
      }

      // Legend
      var lgX = padL;
      var lgY = h - 10;
      ctx.fillStyle = '#1C66FF'; ctx.fillRect(lgX, lgY - 8, 10, 8);
      ctx.fillStyle = '#475569'; ctx.font = '10px system-ui,sans-serif'; ctx.textAlign = 'left';
      ctx.fillText('Total hits', lgX + 13, lgY);
      lgX += 90;
      ctx.fillStyle = '#20C997'; ctx.fillRect(lgX, lgY - 8, 10, 8);
      ctx.fillStyle = '#475569';
      ctx.fillText('2+ match rate', lgX + 13, lgY);
    })();
    [[/script]]
  [[/div]]
  <?php endif; ?>
[[/div]]
<?php endif; ?>

<!-- ==================================================
     Cumulative Runs Report (replaces old "Top Hits" panel)
================================================== -->
<?php if (!empty($bestOpps)): ?>
<?php
// -- debug_hits flag: already set before bestOpps loop; reassign to keep this block self-contained --
$__debugHits = (int)$app->input->get('debug_hits', 0, 'INT') === 1;
// -- CRR helpers (closures; safe to evaluate multiple times inside loop) --
$__crrSourceOrder = [
    'skai_prediction' => 0,
    'ai_prediction'   => 1,
    'skip_hit'        => 2,
    'mcmc_prediction' => 3,
    'heatmap'         => 4,
];
$__crrSourceChipClass = [
    'skai_prediction' => 'skai-crr-src-chip--skai',
    'ai_prediction'   => 'skai-crr-src-chip--ai',
    'skip_hit'        => 'skai-crr-src-chip--skip',
    'mcmc_prediction' => 'skai-crr-src-chip--mcmc',
    'heatmap'         => 'skai-crr-src-chip--heatmap',
];
$__crrGetRunMode = static function (array $run, array $__crrRunMap): string {
    $sRow = $__crrRunMap[(int)$run['run_id']] ?? null;
    if (!$sRow) return '-';
    $src = (string)$run['source'];
    if ($src === 'skai_prediction') {
        $m = trim((string)($sRow->skai_run_mode ?? ''));
        return $m !== '' ? $m : '-';
    }
    if ($src === 'skip_hit' && (int)($sRow->pure_mode ?? 0) === 1) {
        return 'Pure';
    }
    return '-';
};
?>
[[div class="skai-crr-wrap"]]
  [[h2 class="skai-crr-heading"]]Cumulative Runs Report[[/h2]]

  <?php foreach ($bestOpps as $lid => $opp): ?>
  <?php
    $__crrLotName = htmlspecialchars((string)($opp['lottery_name'] ?? ''), ENT_QUOTES);
    $__crrStateName = (string)($opp['state_name'] ?? '');
    $__crrLogo = ['path' => '', 'exists' => false, 'alt' => ''];
    if ($__crrStateName !== '' && $opp['lottery_name'] !== '') {
        $__crrLogo = buildLotteryLogoPath($__crrStateName, (string)$opp['lottery_name']);
    }
    $__crrExtraLabel = getExtraBallLabel((int)$opp['game_id'], $opp['lottery_name'], $configData);

    // Sort all_scored_runs: draw_date DESC, then source order, then total hits DESC, then run_id DESC
    $__crrRuns = $opp['all_scored_runs'] ?? [];
    usort($__crrRuns, function (array $a, array $b) use ($__crrSourceOrder): int {
        // draw_date DESC
        $dc = strcmp($b['draw_date'], $a['draw_date']);
        if ($dc !== 0) return $dc;
        // source order ASC
        $sA = $__crrSourceOrder[$a['source']] ?? 99;
        $sB = $__crrSourceOrder[$b['source']] ?? 99;
        if ($sA !== $sB) return $sA - $sB;
        // total hits DESC
        $tA = (int)$a['hits'] + (int)$a['extra_hits'];
        $tB = (int)$b['hits'] + (int)$b['extra_hits'];
        if ($tA !== $tB) return $tB - $tA;
        // main hits DESC
        if ((int)$a['hits'] !== (int)$b['hits']) return (int)$b['hits'] - (int)$a['hits'];
        // run_id DESC
        return (int)$b['run_id'] - (int)$a['run_id'];
    });

    // Find the overall best run (max total hits, tie-break main hits then most recent draw_date)
    $__crrBestRunId  = -1;
    $__crrBestTotal  = -1;
    $__crrBestMain   = -1;
    $__crrBestDate   = '';
    foreach ($__crrRuns as $__cr) {
        $__tot = (int)$__cr['hits'] + (int)$__cr['extra_hits'];
        if ($__tot > $__crrBestTotal
            || ($__tot === $__crrBestTotal && (int)$__cr['hits'] > $__crrBestMain)
            || ($__tot === $__crrBestTotal && (int)$__cr['hits'] === $__crrBestMain && $__cr['draw_date'] > $__crrBestDate)) {
            $__crrBestTotal  = $__tot;
            $__crrBestMain   = (int)$__cr['hits'];
            $__crrBestDate   = $__cr['draw_date'];
            $__crrBestRunId  = (int)$__cr['run_id'];
        }
    }

    // Best-run summary line data
    $__crrBestRun = null;
    foreach ($__crrRuns as $__cr) {
        if ((int)$__cr['run_id'] === $__crrBestRunId) { $__crrBestRun = $__cr; break; }
    }

    // Collect distinct source types present in this lottery's runs (for filter chips)
    $__crrPresentSrcs = [];
    foreach ($__crrRuns as $__cr) {
        $__crrPresentSrcs[$__cr['source']] = true;
    }
uksort($__crrPresentSrcs, function ($a, $b) use ($__crrSourceOrder) {
    $oa = isset($__crrSourceOrder[$a]) ? (int)$__crrSourceOrder[$a] : 99;
    $ob = isset($__crrSourceOrder[$b]) ? (int)$__crrSourceOrder[$b] : 99;
    if ($oa === $ob) return 0;
    return ($oa < $ob) ? -1 : 1;
});

    $__crrTotal  = count($__crrRuns);
    $__crrUnsco  = max(0, (int)$opp['total_runs'] - $__crrTotal);
  ?>
  [[div class="skai-crr-card" id="crr-<?php echo (int)$lid; ?>"]]

    <!-- Card header: logo + lottery name -->
    [[div class="skai-crr-card__header"]]
      <?php if (!empty($__crrLogo['path'])): ?>
        <?php if ($__crrLogo['exists']): ?>
          [[img class="lotto-logo--sm"
               src="<?php echo htmlspecialchars($__crrLogo['path'], ENT_QUOTES); ?>"
               alt="<?php echo htmlspecialchars($__crrLogo['alt'], ENT_QUOTES); ?>"
               loading="lazy" width="64" height="32"]]
        <?php else: ?>
          [[span class="lotto-logo--sm fallback"
                 aria-label="<?php echo htmlspecialchars($__crrLogo['alt'], ENT_QUOTES); ?>"]]
            <?php
              $__crrPts = preg_split('/\s+/', (string)$opp['lottery_name']);
              $__crrIni = '';
              foreach ($__crrPts as $__crrPt) {
                  if ($__crrPt !== '') { $__crrIni .= mb_strtoupper(mb_substr($__crrPt,0,1)); if (mb_strlen($__crrIni)>=2) break; }
              }
              echo htmlspecialchars($__crrIni ?: 'LE', ENT_QUOTES);
            ?>
          [[/span]]
        <?php endif; ?>
      <?php endif; ?>
      [[span class="skai-crr-card__name"]]<?php echo $__crrLotName; ?>[[/span]]
    [[/div]]

    <!-- Narrative explainer -->
    [[div class="skai-crr-narrative"]]
      This report summarizes every saved run you&#39;ve made for this lottery, grouped by
      analysis type and ordered by draw date (newest first). Use it to spot which method
      and run mode consistently produce the most hits. Once you identify a winner, tweak
      one setting at a time (e.g., draw window, smoothing, epochs/walks) and save a new
      run so you can measure improvement rather than guessing.
    [[/div]]

    <?php if ($__crrBestRun): ?>
    <!-- Best-run summary line -->
    [[div class="skai-crr-best-summary"]]
      &#x2B50; Best run in this history:
      [[strong]]<?php echo htmlspecialchars($__methodLabels[$__crrBestRun['source']] ?? $__crrBestRun['source'], ENT_QUOTES); ?>[[/strong]]
      on [[strong]]<?php echo htmlspecialchars($__crrBestRun['draw_date'], ENT_QUOTES); ?>[[/strong]]
      &mdash;
      <?php if ($__crrBestMain > 0 || (int)$__crrBestRun['extra_hits'] > 0): ?>
        [[span class="skai-crr-hits-main"]]<?php echo $__crrBestMain; ?> main[[/span]]
        <?php if ((int)$__crrBestRun['extra_hits'] > 0): ?>
          + [[span class="skai-crr-hits-extra"]]<?php echo (int)$__crrBestRun['extra_hits']; ?> <?php echo htmlspecialchars($__crrExtraLabel, ENT_QUOTES); ?>[[/span]]
        <?php endif; ?>
      <?php else: ?>
        [[span class="skai-crr-hits-zero"]]0 hits[[/span]]
      <?php endif; ?>
    [[/div]]
    <?php endif; ?>

    <?php if (empty($__crrRuns)): ?>
    <!-- Empty state -->
    [[div class="skai-crr-empty"]]
      No scored runs yet. Save more runs and check back after the draw results are recorded.
      <?php if ($__crrUnsco > 0): ?>
        (<?php echo $__crrUnsco; ?> run<?php echo $__crrUnsco===1?'':'s'; ?> saved but awaiting draw results.)
      <?php endif; ?>
    [[/div]]

    <?php else: ?>
    <!-- Filter chips -->
    <?php if (count($__crrPresentSrcs) > 1): ?>
    [[div class="skai-crr-filters" role="group" aria-label="Filter by analysis type"]]
      [[span class="skai-crr-filters__label"]]Filter:[[/span]]
      <?php foreach (array_keys($__crrPresentSrcs) as $__crrFs): ?>
        <?php $__crrFlbl = htmlspecialchars($__methodLabels[$__crrFs] ?? ucwords(str_replace('_',' ',$__crrFs)), ENT_QUOTES); ?>
        [[button type="button"
                 class="skai-crr-filter-chip"
                 aria-pressed="true"
                 data-crr-lid="<?php echo (int)$lid; ?>"
                 data-crr-src="<?php echo htmlspecialchars($__crrFs, ENT_QUOTES); ?>"
                 aria-label="Toggle <?php echo $__crrFlbl; ?> filter"]]
          <?php echo $__crrFlbl; ?>
        [[/button]]
      <?php endforeach; ?>
    [[/div]]
    <?php endif; ?>

    <!-- Runs table -->
    [[div style="overflow-x:auto;"]]
    [[table class="skai-crr-table" aria-label="All scored runs for <?php echo $__crrLotName; ?>"
      data-debug-hits="<?php echo $__debugHits ? '1' : '0'; ?>"
      data-crr-extra-label="<?php echo htmlspecialchars($__crrExtraLabel, ENT_QUOTES); ?>"]]
      [[thead]]
        [[tr]]
          [[th scope="col"]]Type[[/th]]
          [[th scope="col"]]Draw Date[[/th]]
          [[th scope="col"]]Run Mode[[/th]]
          [[th scope="col"]]Hits[[/th]]
        [[/tr]]
      [[/thead]]
      [[tbody]]
        <?php foreach ($__crrRuns as $__cr):
          $__crIsBest   = ((int)$__cr['run_id'] === $__crrBestRunId);
          $__crSrc      = (string)$__cr['source'];
          $__crLabel    = htmlspecialchars($__methodLabels[$__crSrc] ?? ucwords(str_replace('_',' ',$__crSrc)), ENT_QUOTES);
          $__crChipCls  = $__crrSourceChipClass[$__crSrc] ?? 'skai-crr-src-chip--other';
          $__crRunMode  = htmlspecialchars($__crrGetRunMode($__cr, $__crrRunMap), ENT_QUOTES);
          $__crHitsMain = (int)$__cr['hits'];
          $__crHitsEx   = (int)$__cr['extra_hits'];
          $__crDateSafe = htmlspecialchars($__cr['draw_date'], ENT_QUOTES);
          $__crRowCls   = 'skai-crr-row' . ($__crIsBest ? ' skai-crr-row--best' : '');
        ?>
        [[tr class="<?php echo $__crRowCls; ?>"
            data-crr-lid="<?php echo (int)$lid; ?>"
            data-crr-src="<?php echo htmlspecialchars($__crSrc, ENT_QUOTES); ?>"
            data-run-id="<?php echo (int)$__cr['run_id']; ?>"]]
          [[td data-label="Type"]]
            [[span class="skai-crr-src-chip <?php echo $__crChipCls; ?>"]]<?php echo $__crLabel; ?>[[/span]]
            <?php if ($__crIsBest): ?>
              [[span class="skai-crr-badge-best" aria-label="Best run"]]Best[[/span]]
            <?php endif; ?>
          [[/td]]
          [[td data-label="Draw Date"]]<?php echo $__crDateSafe; ?>[[/td]]
          [[td data-label="Run Mode"]]<?php echo $__crRunMode; ?>[[/td]]
          [[td data-label="Hits" data-crr-hits-cell="1"]]
            <?php if ($__crHitsMain === 0 && $__crHitsEx === 0): ?>
              [[span class="skai-crr-hits-zero"]]0[[/span]]
            <?php else: ?>
              [[span class="skai-crr-hits-main"]]<?php echo $__crHitsMain; ?>[[/span]]
              <?php if ($__crHitsEx > 0): ?>
                [[span class="skai-crr-hits-extra"]] +<?php echo $__crHitsEx; ?> <?php echo htmlspecialchars($__crrExtraLabel, ENT_QUOTES); ?>[[/span]]
              <?php endif; ?>
            <?php endif; ?>
          [[/td]]
        [[/tr]]
        <?php if ($__debugHits): ?>
        [[tr class="skai-crr-debug-row"]]
          [[td colspan="4" style="font-size:0.72rem;background:#fffbe6;color:#555;padding:0.35rem 0.55rem;border-bottom:1px solid #f1e4a0;"]]
            <strong>Debug run #<?php echo (int)$__cr['run_id']; ?></strong> &nbsp;|&nbsp;
            game_id: <?php echo (int)(isset($__cr['game_id']) ? $__cr['game_id'] : (isset($opp['game_id']) ? $opp['game_id'] : 0)); ?> &nbsp;|&nbsp;
            source: <?php echo htmlspecialchars((string)($__cr['source'] ?? ''), ENT_QUOTES); ?> &nbsp;|&nbsp;
            draw_date: <?php echo $__crDateSafe; ?> &nbsp;|&nbsp;
            drawMain: [<?php echo implode(',', array_map('intval', (array)($__cr['actual_main'] ?? []))); ?>] &nbsp;|&nbsp;
            drawExtra: [<?php echo (int)($__cr['actual_extra'] ?? 0); ?>] &nbsp;|&nbsp;
            predMain: [<?php echo implode(',', array_map('intval', (array)($__cr['pred_main'] ?? []))); ?>] &nbsp;|&nbsp;
            predExtra: <?php echo (int)($__cr['pred_extra'] ?? 0); ?> &nbsp;|&nbsp;
            hits_main: <?php echo $__crHitsMain; ?> &nbsp;|&nbsp;
            hits_extra: <?php echo $__crHitsEx; ?> &nbsp;|&nbsp;
            reason: scored
          [[/td]]
        [[/tr]]
        <?php endif; ?>
        <?php endforeach; ?>
      [[/tbody]]
    [[/table]]
    [[/div]]

    <?php if ($__crrUnsco > 0): ?>
    [[div style="font-size:0.75rem; color:#94a3b8; margin-top:0.5rem; text-align:right;"]]
      + <?php echo $__crrUnsco; ?> run<?php echo $__crrUnsco===1?'':'s'; ?> saved but awaiting draw results (not shown above).
    [[/div]]
    <?php endif; ?>

    <?php if ($__debugHits && !empty($opp['awaiting_debug'])): ?>
    [[div style="margin-top:0.6rem;padding:0.55rem 0.75rem;background:#fff8e1;border:1px solid #ffecb3;border-radius:6px;font-size:0.72rem;color:#555;"]]
      [[strong]]Debug: Awaiting Runs (draw not resolved)[[/strong]]
      <?php foreach ($opp['awaiting_debug'] as $__aw): ?>
      [[div style="margin-top:4px;padding-top:4px;border-top:1px solid #ffecb3;"]]
        run_id: <?php echo (int)$__aw['run_id']; ?> &nbsp;|&nbsp;
        game_id: <?php echo (int)$__aw['game_id']; ?> &nbsp;|&nbsp;
        norm_date: <?php echo htmlspecialchars($__aw['norm_date'], ENT_QUOTES); ?> &nbsp;|&nbsp;
        source: <?php echo htmlspecialchars($__aw['source'], ENT_QUOTES); ?> &nbsp;|&nbsp;
        reason: <?php echo htmlspecialchars($__aw['reason'], ENT_QUOTES); ?>
      [[/div]]
      <?php endforeach; ?>
    [[/div]]
    <?php endif; ?>

    <?php endif; // end empty/non-empty ?>

  [[/div]]
  <?php endforeach; ?>
[[/div]]

[[script]]
(function () {
  // Cumulative Runs Report: filter chip toggle (ES5-safe)
  document.addEventListener('click', function (ev) {
    var t = ev.target || null;
    if (!t) return;

    function closestEl(el, cls) {
      while (el && el !== document) {
        if (el.classList && el.classList.contains(cls)) return el;
        el = el.parentNode;
      }
      return null;
    }

    var chip = closestEl(t, 'skai-crr-filter-chip');
    if (!chip) return;

    var lid = chip.getAttribute('data-crr-lid') || '';
    var isOn = (chip.getAttribute('aria-pressed') === 'true');
    chip.setAttribute('aria-pressed', isOn ? 'false' : 'true');

    var allChips = document.querySelectorAll('.skai-crr-filter-chip[data-crr-lid="' + lid + '"]');
    var active = [];
    var i;

    for (i = 0; i < allChips.length; i++) {
      if (allChips[i].getAttribute('aria-pressed') === 'true') {
        active.push(allChips[i].getAttribute('data-crr-src'));
      }
    }

    var rows = document.querySelectorAll('.skai-crr-row[data-crr-lid="' + lid + '"]');
    for (i = 0; i < rows.length; i++) {
      var rowSrc = rows[i].getAttribute('data-crr-src') || '';
      rows[i].style.display = (active.length === 0 || active.indexOf(rowSrc) !== -1) ? '' : 'none';
    }
  });
})();
[[/script]]

<?php endif; ?>
<!-- ==================================================
     Saved Predictions & Numbers Section $unionSql
================================================== -->

<!-- Legend will appear after first prediction group via JavaScript -->
[[div id="legend-card-placeholder" class="skai-card legend-card" style="display:none; margin:1rem 0;"]]
  [[div class="skai-card__header"]]
    [[strong]]Method Colors[[/strong]]
  [[/div]]
  [[div class="legend-container"]]
    [[span class="legend-box legend-skip"]]Skip &amp; Hit[[/span]]
    [[span class="legend-box legend-ai"]]AI[[/span]]
    [[span class="legend-box legend-skai"]]SKAI[[/span]]
    [[span class="legend-box legend-mcmc"]]MCMC[[/span]]
    [[span class="legend-box legend-heatmap"]]Frequency Map[[/span]]
  [[/div]]
[[/div]]
[[style]]
/* Legend card spacing & title alignment */
.legend-card{
  /* add breathing room under the "How to use this dashboard" card */
  margin-top: 1.25rem;
  border-radius: 10px;
}

/* tighten but balance the header */
.legend-card .card-header{
  padding: 0.4rem 1rem 0.25rem;
}

.legend-card .card-header strong{
  font-size: 0.85rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: #6b7280;
}
.legend-card .legend-box.legend-skai::before{
  background-color:#06b6d4; /* teal for SKAI */
}

/* give the pills some space inside the card */
.legend-card .legend-container{
  padding: 0.35rem 1rem 0.75rem;
}

/* keep legend items on one "calm" line with nice gaps */
.legend-card .legend-box{
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  margin-right: 1.25rem;
}
[[/style]]



<?php foreach ($groups as $g):
  $lottoUrl = $lottoUrlsById[$g['lottery_id']] ?? null;
  $meta     = $lottoMetaById[$g['lottery_id']] ?? [];
  $state    = $meta['state'] ?? '';
  $country  = $meta['country'] ?? '';
  // Compute a safe HTML-ID fragment for this group's collapsible content
  $__lcId   = 'lc-' . (int)$g['lottery_id'] . '-' . preg_replace('/[^a-z0-9]/i', '-', (string)($g['draw_date'] ?? ''));
  // Best-avg-hits data for Best Settings Snapshot
  $__bavData = $bestOpps[(int)$g['lottery_id']]['best_avg_hits'] ?? ['run_id'=>0,'avg_hits'=>0,'scored_runs'=>0,'last_scored_date'=>'','display_name'=>''];
  $__bavLotName = trim(($country ? $country . ' ' : '') . ($state && $state !== 'Lottery' ? $state : '') . ' ' . ($g['lottery_name'] ?? ''));
  $__bavLotName = $__bavLotName !== '' ? $__bavLotName : (string)($g['lottery_name'] ?? '');
  // Sparkline and best-single-hits for snapshot card
  $__spkLid = (int)$g['lottery_id'];
  $__spkRuns = isset($bestOpps[$__spkLid]['all_scored_runs']) ? $bestOpps[$__spkLid]['all_scored_runs'] : [];
  $__spkByDate = [];
  foreach ($__spkRuns as $__spkR) {
      $__spkD = (string)$__spkR['draw_date'];
      if (!isset($__spkByDate[$__spkD]) || (int)$__spkR['hits'] > $__spkByDate[$__spkD]) {
          $__spkByDate[$__spkD] = (int)$__spkR['hits'];
      }
  }
  ksort($__spkByDate);
  $__spkData = array_values($__spkByDate);
  $__bestSingleHits = isset($bestOpps[$__spkLid]['best_rank']['hits']) ? (int)$bestOpps[$__spkLid]['best_rank']['hits'] : 0;
?>

[[div class="lottery-group card skai-card collapsed"
     data-lottery-id="<?php echo (int)$g['lottery_id']; ?>"
     data-draw-date="<?php echo htmlspecialchars($g['draw_date'] ?? '', ENT_QUOTES); ?>"
     data-lottery-name="<?php echo htmlspecialchars(trim($__bavLotName), ENT_QUOTES); ?>"
     data-best-avg-run-id="<?php echo (int)$__bavData['run_id']; ?>"
     data-best-avg-hits="<?php echo htmlspecialchars((string)$__bavData['avg_hits'], ENT_QUOTES); ?>"
     data-best-avg-scored="<?php echo (int)$__bavData['scored_runs']; ?>"
     data-best-avg-lastdate="<?php echo htmlspecialchars((string)$__bavData['last_scored_date'], ENT_QUOTES); ?>"
     data-best-avg-name="<?php echo htmlspecialchars((string)$__bavData['display_name'], ENT_QUOTES); ?>"
     data-best-single-hits="<?php echo $__bestSingleHits; ?>"
     data-sparkline="<?php echo htmlspecialchars(json_encode($__spkData, JSON_NUMERIC_CHECK), ENT_QUOTES); ?>"]]
  [[div class="lottery-header skai-card__header" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:0.5rem;"]]
    [[div style="display:flex; align-items:center; flex:1; flex-wrap:wrap; gap:0.5rem;"]]

<?php
  // Build logo for this group (uses state name & lottery name from your meta)
  $logo = ['path'=>'','exists'=>false,'alt'=>''];
  if (!empty($state) && !empty($g['lottery_name'])) {
      $logo = buildLotteryLogoPath((string)$state, (string)$g['lottery_name']);
  }
?>

[[div class="lotto-title-wrap"]]
  <?php if (!empty($logo['path'])): ?>
    <?php if ($logo['exists']): ?>
      [[img class="lotto-logo" src="<?php echo htmlspecialchars($logo['path'], ENT_QUOTES); ?>"
            alt="<?php echo htmlspecialchars($logo['alt'], ENT_QUOTES); ?>" loading="lazy" width="120" height="38"]]
    <?php else: ?>
      [[span class="lotto-logo fallback" aria-label="<?php echo htmlspecialchars($logo['alt'].' (placeholder)', ENT_QUOTES); ?>"]]
        <?php
          // simple fallback initials from lottery name (2 letters)
          $parts = preg_split('/\s+/', (string)$g['lottery_name']);
          $ini   = '';
          foreach ($parts as $p) { if ($p !== '') { $ini .= mb_strtoupper(mb_substr($p,0,1)); if (mb_strlen($ini) >= 2) break; } }
          echo htmlspecialchars($ini ?: 'LE', ENT_QUOTES);
        ?>
      [[/span]]
    <?php endif; ?>
  <?php endif; ?>

[[h2 class="lottery-title skai-card__title" style="margin:0;"]]
  [[span class="lottery-name"]]
    <?php if ($country) echo htmlspecialchars($country, ENT_QUOTES); ?>
  [[/span]]

  <?php if ($state && $state !== 'Lottery'): ?>
    &nbsp;�&nbsp;
    [[span class="lottery-name"]]
      <?php echo htmlspecialchars($state, ENT_QUOTES); ?>
    [[/span]]
  <?php endif; ?>

<?php
  // Resolve a correct lottery name (Powerball, Mega Millions, etc.)
  $lotName = '';
  if (!empty($g['lottery_name'])) {
      $lotName = $g['lottery_name'];
  } elseif (!empty($g['game_name'])) {
      $lotName = $g['game_name'];
  } elseif (!empty($s->lottery_name)) {
      $lotName = $s->lottery_name;
  }
?>

<?php if (!empty($lotName)): ?>
  &nbsp;�&nbsp;
  [[span class="lottery-name"]]
    <?php echo htmlspecialchars($lotName, ENT_QUOTES); ?>
  [[/span]]
<?php endif; ?>

  &nbsp;�&nbsp;
  <?php
    $ts = strtotime((string) ($g['draw_date'] ?? ''));
    echo $ts ? date('M j, Y', $ts) : htmlspecialchars((string) ($g['draw_date'] ?? ''), ENT_QUOTES);
  ?>
[[/h2]]

[[/div]]

  <!-- disabled "Pick Another Analysis" button -->
<?php if ($lottoUrl): ?>
  [[a href="<?php echo htmlspecialchars($lottoUrl, ENT_QUOTES); ?>"
       target="_blank"
       rel="noopener"
       class="btn-primary"
       style="margin-left:1rem; font-size:0.75rem; padding:0.25rem 0.6rem;"]]
     Official Results Page
  [[/a]]
<?php endif; ?>


[[details class="skai-adv-tools-details" style="display:inline-block; margin-left:.5rem;"]]
  [[summary class="skai-adv-tools-summary" aria-label="Advanced tools for this draw"]]Advanced[[/summary]]
  [[div class="skai-adv-tools-body"]]
  [[form method="post"
         onsubmit="return confirm('Delete all predictions for this draw?');"]]
    <?php echo \Joomla\CMS\HTML\HTMLHelper::_('form.token'); ?>
    [[input type="hidden" name="delete_lottery_predictions" value="1"]]
    [[input type="hidden" name="lottery_id" value="<?php echo (int)$g['lottery_id']; ?>"]]
    [[input type="hidden" name="draw_date"  value="<?php echo htmlspecialchars($g['draw_date'], ENT_QUOTES); ?>"]]
    [[button type="submit" class="btn-danger" style="padding:0.25rem 0.6rem; font-size:0.75rem;"]]
      Delete This Draw
    [[/button]]
  [[/form]]
  [[/div]]
[[/details]]
    [[/div]]

    <?php
    // Mini-summary: show best 3 for this lottery in the collapsed header
    $__glid  = (int)$g['lottery_id'];
    $__gopp  = isset($bestOpps[$__glid]) ? $bestOpps[$__glid] : null;
    if ($__gopp):
      $__gba = $__gopp['best_agreement'];
      $__gbr = $__gopp['best_rank'];
    ?>
    [[div class="skai-lottery-summary"]]
      <?php if ($__gba['score'] > 0): ?>
      [[span class="skai-lottery-summary__chip"]]
        Agreement: [[b]]<?php echo (int)$__gba['score']; ?> shared[[/b]]
      [[/span]]
      <?php endif; ?>
      <?php if ($__gbr['hits'] > 0): ?>
      [[span class="skai-lottery-summary__chip"]]
        Rank hits: [[b]]<?php echo (int)$__gbr['hits']; ?>[[/b]]
      [[/span]]
      <?php endif; ?>
      [[a href="#best-opp-<?php echo $__glid; ?>" class="skai-lottery-summary__chip"
         style="color:#1C66FF; text-decoration:none;"]]See Best Opportunities &uarr;[[/a]]
    [[/div]]
    <?php endif; ?>
    
    [[button type="button" class="lottery-collapse-toggle btn-secondary" 
             style="padding:0.35rem 0.75rem; font-size:0.8rem; white-space:nowrap;"
             aria-expanded="false"
             aria-label="Expand lottery section"
             aria-controls="<?php echo htmlspecialchars($__lcId, ENT_QUOTES); ?>"]]
      Expand
    [[/button]]
  [[/div]]
  
  [[div class="lottery-collapsible-content" id="<?php echo htmlspecialchars($__lcId, ENT_QUOTES); ?>"]]

      <?php
        // --------------------------------------------------------------
        // GROUP-LEVEL: Placement & Performance Stats (per lottery/draw)
        // Derives rank positions from the order of saved main_numbers.
        // --------------------------------------------------------------

        // Build per-method buckets for this group
$methodKeys = ['skip_hit','ai_prediction','mcmc_prediction','skai_prediction','heatmap'];
        $predsBySource = [];
        foreach ($g['preds'] as $__r) { $predsBySource[$__r->source][] = $__r; }

        // Fetch single actual draw row for the group (once)
$__fields   = getDrawFields($g['game_id']);

// Normalize date for drawMap lookup
$__normDate = $g['draw_date'];
try {
    $dt = new DateTime($g['draw_date']);
    $__normDate = $dt->format('Y-m-d');
} catch (Exception $e) {
    $__normDate = $g['draw_date'];
}

$__drawKey = (int)$g['game_id'] . '|' . $__normDate;

// Use preloaded $drawMap first; fallback to getDrawByDate()
if (!empty($drawMap) && isset($drawMap[$__drawKey])) {
    $__drawRow = $drawMap[$__drawKey];
} else {
    $__drawRow = getDrawByDate($g['game_id'], $g['draw_date'], $db);
}

$__drawMain = [];
$__drawExtra = [];

if ($__drawRow) {

$__mode = (!empty($__drawMain)) ? 'post' : 'pre';

  // If row came from $drawMap, normalized fields exist (main_0, main_1, �, extra_ball)
  $hasNormalizedMains = array_key_exists('main_0', $__drawRow);

  if ($hasNormalizedMains) {
      // Read normalized main fields
      for ($i = 0; $i < 25; $i++) { // 25 = safe max; unused indexes ignored
          $key = 'main_' . $i;
          if (isset($__drawRow[$key]) && $__drawRow[$key] !== '' && $__drawRow[$key] !== null) {
              $__drawMain[] = (int)$__drawRow[$key];
          }
      }

      // Normalized extra ball (optional) - ONLY if this game supports an extra ball per config
      if (!empty($__fields['extra']) &&
          isset($__drawRow['extra_ball']) &&
          $__drawRow['extra_ball'] !== '' &&
          $__drawRow['extra_ball'] !== null) {
          $__drawExtra[] = (int)$__drawRow['extra_ball'];
      }

  } else {
      // FALLBACK MODE: use original column names from config
      foreach (($__fields['main'] ?? []) as $__c) {
          if (isset($__drawRow[$__c]) && $__drawRow[$__c] !== '') {
              $__drawMain[] = (int)$__drawRow[$__c];
          }
      }

      if (!empty($__fields['extra']) &&
          isset($__drawRow[$__fields['extra']]) &&
          $__drawRow[$__fields['extra']] !== '') {
          $__drawExtra[] = (int)$__drawRow[$__fields['extra']];
      }
  }
}

        // Limits: up to 20 main ranks, up to 5 extra ranks (safe defaults)
        $__maxRankMain  = 20;
        $__maxRankExtra = 5;

        // Initialize stats
        $placementStats = [];
        $hitsByMethod   = [];
        $predCountByMethod = [];
        foreach ($methodKeys as $__m) {
          $placementStats[$__m] = [
            'main'  => array_fill(1, $__maxRankMain, 0),
            'extra' => array_fill(1, $__maxRankExtra, 0),
            'main_hits_total'  => 0,
            'extra_hits_total' => 0
          ];
          $hitsByMethod[$__m] = ['runs'=>0,'mainHits'=>0,'extraHits'=>0];
          $predCountByMethod[$__m] = isset($predsBySource[$__m]) ? count($predsBySource[$__m]) : 0;
        }

        $totalRuns = count($g['preds']);
        $totalMainHits = 0;
        $totalExtraHits = 0;

        // Helper: map number -> position (1-based) for a prediction string
        $toPositions = function (string $csv, int $limit) {
          $arr = array_values(array_filter(array_map('trim', explode(',', $csv)), fn($v)=>$v!==''));
          $pos = [];
          foreach ($arr as $i => $n) {
            if ($i >= $limit) break;
            $num = (int)$n;
            if (!isset($pos[$num])) { $pos[$num] = $i + 1; } // first occurrence = rank
          }
          return $pos;
        };

        // (old placement stats block removed - see GROUP-LEVEL
        // Placement & Performance Stats block below for the
        // live implementation)
      ?>

      <?php

      ?>

[[style]]
/* -- Placement Heatmap (mains) ------------------------------------ */
.placement-wrap{
  display:flex;
  flex-direction:column;
  gap:0.75rem;
  margin:1.25rem 0;
}
.placement-grid{
  overflow:auto;
  border-radius:10px;
  border:1px solid #dde3f0;
  background:linear-gradient(135deg,#f9fbff 0,#ffffff 45%,#f3f4fb 100%);
  box-shadow:0 10px 22px rgba(15,23,42,0.05);
}
.placement-grid table{
  border-collapse:separate;
  border-spacing:0;
  width:100%;
  min-width:520px;
  font-variant-numeric:tabular-nums;
}
.placement-grid th,
.placement-grid td{
  border:1px solid #e0e0e5;
  padding:.35rem .5rem;
  text-align:center;
  font-size:.85rem;
}

/* Header row */
.placement-grid thead th{
  position:sticky;
  top:0;
  background:linear-gradient(180deg,#f3f6ff 0,#e9efff 50%,#e3e9fb 100%);
  z-index:2;
  font-size:.8rem;
  font-weight:700;
  color:#1f2937;
  letter-spacing:.04em;
  text-transform:uppercase;
  border-bottom-color:#cbd5f1;
}

/* First & last columns (labels + total) */
.placement-grid thead th:first-child,
.placement-grid tbody td:first-child{
  text-align:left;
  padding-left:.7rem;
  font-weight:700;
  background:rgba(248,250,252,0.9);
  position:sticky;
  left:0;
  z-index:3;
}
.placement-grid thead th:last-child,
.placement-grid tbody td:last-child{
  font-weight:700;
  background:rgba(249,250,251,0.98);
}

/* zero values = pale cell so you can still see the grid */
.placement-grid td.zero{
  background:#F5F7FB;
  color:#556;
}

.placement-grid td{
  position:relative;
  letter-spacing:.2px;
  transition:
    box-shadow .12s ease,
    transform .08s ease,
    border-color .12s ease;
}

/* Peak outline stays as your gold "target" */
.placement-grid td.cell-peak{
  box-shadow:inset 0 0 0 2px #ffb000;
}

/* Row hover - subtle lift without killing inline heat colors */
.placement-grid tbody tr:hover td{
  border-color:#cbd5f5;
}
.placement-grid tbody tr:hover td:not(.zero){
  box-shadow:inset 0 0 0 1px rgba(15,23,42,0.08);
}

/* Helper narrative text below/above tables */
.helper{
  font-size:.95rem;
  color:#2c3e50;
  line-height:1.5;
}

/* ============================================================
   WORLD-CLASS UI PRIMITIVES: BALLS, TAGS, BULLETS, SECTIONS
   ============================================================ */

/* Generic badges (used for PURE-SKIP + gray ID tags) */
.badge{
  background:#eef4ff;
  color:#204a8e;
  border:1px solid #cfdaf5;
  padding:.15rem .5rem;
  border-radius:999px;
  font-size:.8rem;
  font-weight:600;
}
.badge.gray{
  background:#f6f6f6;
  color:#444;
  border-color:#e0e0e0;
}
.badge.pure-badge{
  background:#ffe9cc;
  color:#7a3e00;
  border:1px solid #ffc680;
  letter-spacing:.2px;
}

/* Number pills / "balls" */
.ball{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-width:2rem;
  height:2rem;
  padding:0 .55rem;
  border-radius:999px;
  background:#f5f7ff;
  border:1px solid #d6dcf4;
  font-size:.9rem;
  font-weight:600;
  color:#1f2937;
  letter-spacing:.01em;
  box-shadow:0 1px 1px rgba(30,58,138,0.04);
}
.ball-hit{
  background:#16a34a;
  border-color:#15803d;
  color:#ffffff;
}
.ball-ovl{
  background:#1C66FF;
  border-color:#0A1A33;
  color:#ffffff;
}

/* Tag chips under numbers (HIT / OVL / AI / SH / MCMC / HM) */
.btag{
  display:inline-block;
  padding:0.15rem 0.45rem;
  margin:0.15rem 0;
  border-radius:6px;
  font-size:.7rem;
  font-weight:600;
  text-transform:uppercase;
  letter-spacing:.04em;
  color:#1e293b;
  background:#f2f4f7;
  border:1px solid #d3d8df;
}
.btag-hit{
  background:#16a34a;
  border-color:#15803d;
  color:#ffffff;
}
.btag-ovl{
  background:#1C66FF;
  border-color:#0A1A33;
  color:#ffffff;
}
/* Method-specific tag colors - aligned with card headers */
.btag-ai{
  /* AI = green */
  background:#dcfce7;
  border-color:#bbf7d0;
  color:#166534;
}
.btag-sh{
  /* Skip & Hit = blue */
  background:#e0f2fe;
  border-color:rgba(28,102,255,0.30);
  color:#1C66FF;
}
.btag-mm{
  /* MCMC = purple */
  background:#ede9fe;
  border-color:#ddd6fe;
  color:#5b21b6;
}
.btag-hm{
  /* Heatmap = orange */
  background:#ffedd5;
  border-color:#fdba74;
  color:#c2410c;
}
.btag-sk{
  /* SKAI = teal */
  background:#cffafe;
  border-color:#a5f3fc;
  color:#0e7490;
}

/* Chip rows */
.chips{
  display:flex;
  flex-wrap:wrap;
  gap:0.35rem .5rem;
  margin-top:0.25rem;
}
.ball-tags{
  display:flex;
  flex-wrap:wrap;
  gap:0.25rem;
  margin-top:0.25rem;
}

/* Section headers for narrative/card blocks */
.section-h{
  font-size:1rem;
  font-weight:700;
  margin-bottom:0.35rem;
  color:#1f2a3a;
  letter-spacing:0.01em;
}

/* ===== SKAI Status Strip (scan-friendly narrative header) ===== */
.skai-status-strip{
  border:1px solid rgba(10,26,51,0.10);
  border-radius:12px;
  padding:10px 12px;
  margin:10px 0 12px;
  background: linear-gradient(180deg, #EFEFF5 0%, #FFFFFF 100%); /* Slate Mist */
  box-shadow:0 10px 22px rgba(10,26,51,0.06);
  display:flex;
  justify-content:space-between;
  gap:12px;
  flex-wrap:wrap;
}
.skai-status-left{
  display:flex;
  align-items:center;
  gap:8px;
  flex-wrap:wrap;
}
.skai-status-dot{
  width:10px;
  height:10px;
  border-radius:999px;
  display:inline-block;
}
.skai-status-text{
  color:#0A1A33;
  font-size:.92rem;
}
.skai-status-right{
  display:flex;
  gap:10px;
  flex-wrap:wrap;
  align-items:center;
}
.skai-status-mini{
  color:#7F8DAA;
  font-size:.88rem;
  white-space:nowrap;
}
@media (max-width:640px){
  .skai-status-strip{
    padding:10px 10px;
  }
}

.s-row{
  margin-bottom:0.35rem;
}
.s-row.subtle{
  font-size:0.9rem;
  color:#475569;
}
.bullet{
  margin-left:1rem;
  color:#374151;
  font-size:.88rem;
  margin-bottom:0.3rem;
  line-height:1.45;
}

/* Divider for narrative sections */
.narrative-divider{
  border-top:1px solid #e3e7ef;
  margin:0.75rem 0;
}



/* Common "agreement bar" styling */
.prediction-card.common-card{
  border-style:dashed;
  border-color:#c4d1f5;
  background:linear-gradient(135deg,#f8fbff 0%,#fdfdff 48%,#f5f7ff 100%);
  padding-top:0.75rem;
  padding-bottom:0.75rem;
  margin-bottom:1.25rem;
}
.prediction-card.common-card .card-header{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:0.75rem;
  flex-wrap:wrap;
  margin-bottom:0.35rem;
}
.common-title-main{
  display:flex;
  align-items:center;
  gap:0.35rem;
  font-size:0.95rem;
  font-weight:700;
  color:#111827;
}
.common-title-main .label{
  font-size:0.78rem;
  letter-spacing:.06em;
  text-transform:uppercase;
  color:#6b7280;
}
.common-title-sub{
  font-size:0.82rem;
  color:#4b5563;
  margin-top:0.1rem;
}
.prediction-card.common-card .common-count{
  display:inline-flex;
  align-items:center;
  padding:0.15rem 0.6rem;
  border-radius:999px;
  font-size:0.78rem;
  font-weight:600;
  background:rgba(28,102,255,0.08);
  border:1px solid rgba(28,102,255,0.22);
  color:#0A1A33;
  white-space:nowrap;
}

/* Area where JS injects overlapping numbers */
.common-numbers{
  padding:0.6rem 0.25rem 0.15rem;
  border-top:1px dashed #dbe3f7;
  margin-top:0.25rem;
}
.common-numbers > div{
  margin-bottom:0.25rem;
  font-size:0.85rem;
  color:#374151;
}
/* Overlap highlight (SKAI/LottoExpert pill) */
.common-highlight{
  display:inline-flex;
  align-items:center;
  justify-content:center;

  border-radius:999px;
  padding:0.18rem 0.6rem;
  margin:0.12rem 0.18rem 0.12rem 0;

  background:#1C66FF;           /* SKAI Blue */
  border:1px solid rgba(28,102,255,0.35);
  color:#fff;
  font-weight:700;
  font-size:0.92rem;
  line-height:1;
  min-width:2.2rem;

  box-shadow:0 6px 18px rgba(10,26,51,0.10);
  transition:transform .12s ease, box-shadow .12s ease, filter .12s ease;
}

/* Clickable "pick" behavior for Decision Set */
.le-pick-pill{
  cursor:pointer;
  user-select:none;
}
.le-pick-pill:hover{
  transform:translateY(-1px);
  box-shadow:0 10px 22px rgba(10,26,51,0.14);
}
.le-pick-pill:focus{
  outline:2px solid rgba(28,102,255,0.45);
  outline-offset:2px;
}

/* Selected state (same base styling, slightly stronger clarity) */
/* =========================================================
   Decision Builder - grid pills: selected must be obvious
   ========================================================= */

/* Base grid pills (unselected): calm, readable */
.prediction-panel .le-pick-pill.common-highlight{
  background:#EFF6FF;              /* very light blue tint */
  border:1px solid rgba(28,102,255,0.25);  /* SKAI Blue light outline */
  color:#0A1A33;                   /* Deep Navy */
  box-shadow:none;
  transition:transform .08s ease, box-shadow .12s ease, background .12s ease, border-color .12s ease, color .12s ease;
}

/* Hover/focus affordance for accessibility */
.prediction-panel .le-pick-pill.common-highlight:hover{
  border-color:#60A5FA;
  box-shadow:0 1px 4px rgba(10,26,51,.10);
}
.prediction-panel .le-pick-pill.common-highlight:focus{
  outline:3px solid rgba(28,102,255,.35); /* SKAI Blue ring */
  outline-offset:2px;
}

/* SELECTED state: high-contrast SKAI Blue + crisp ring */
.prediction-panel .le-pick-pill.le-is-selected{
  background:#1C66FF !important;   /* SKAI Blue */
  border-color:#0A1A33 !important; /* Deep Navy border for contrast */
  color:#FFFFFF !important;
  box-shadow:
    0 6px 14px rgba(10,26,51,.18),
    0 0 0 2px rgba(255,255,255,.85) inset; /* inner ring */
  transform:translateY(-1px);
}

/* Selected + hover: slightly stronger, still tasteful */
.prediction-panel .le-pick-pill.le-is-selected:hover{
  box-shadow:
    0 8px 18px rgba(10,26,51,.22),
    0 0 0 2px rgba(255,255,255,.90) inset;
}

/* Selected chips in the "Decision pool" display should also be obvious */
.prediction-panel .le-pick-selected.le-is-selected,
.prediction-panel .le-pick-selected.common-highlight{
  background:#1C66FF !important;
  border:1px solid #0A1A33 !important;
  color:#FFFFFF !important;
  box-shadow:0 6px 14px rgba(10,26,51,.16);
}
.le-pick-selected{
  cursor:pointer;
  user-select:none;
}

/* When an overlapping number is ALSO a drawn hit */
.drawn-match{
  background:#16a34a !important;
  border-color:#15803d !important;
  color:#ffffff !important;
  box-shadow:0 0 0 1px rgba(15,118,110,.35);
}



/* ============================================================
   WC-010: subtle micro-animations (respects reduced motion)
   ============================================================ */
@media (prefers-reduced-motion: no-preference){
  .ball,
  .btag,
  .prediction-card,
  .btn-primary,
  .btn-insight{
    transition:
      transform 150ms ease-out,
      box-shadow 150ms ease-out,
      background-color 150ms ease-out,
      border-color 150ms ease-out,
      color 150ms ease-out;
  }

  .prediction-card:hover{
    transform:translateY(-1px);
    box-shadow:0 6px 14px rgba(15,23,42,0.08);
  }

  .btn-primary:hover,
  .btn-insight:hover{
    transform:translateY(-0.5px);
    box-shadow:0 4px 10px rgba(30,64,175,0.22);
  }

  .ball:hover{
    transform:translateY(-0.5px);
    box-shadow:0 3px 8px rgba(15,23,42,0.12);
  }

  .btag:hover{
    transform:translateY(-0.25px);
    box-shadow:0 2px 6px rgba(15,23,42,0.08);
  }
}

/* ============================================================
   WC-011: Print / export friendly layout
   ============================================================ */
@media print{
  /* Reset backgrounds & shadows for clean PDF output */
  body,
  .prediction-panel,
  .lottery-group,
  .prediction-card,
  .common-card{
    background:#ffffff !important;
    box-shadow:none !important;
  }

  .card,
  .prediction-card,
  .common-card{
    border-color:#999999 !important;
  }

  .helper,
  .narrative,
  .section-h,
  .bullet{
    color:#000000 !important;
  }

  /* Keep tables crisp on paper */
  .placement-grid table,
  .placement-grid th,
  .placement-grid td{
    border-color:#444444 !important;
  }

  /* Simplify heatmap colors slightly in print for clarity */
  .placement-grid td.zero{
    background:#f3f4f6 !important;
    color:#374151 !important;
  }

  /* Hide purely interactive chrome */
  .btn-primary,
  .btn-insight,
  .card-compare-checkbox,
  .settings-toggle{
    display:none !important;
  }

  /* Avoid truncated chips / balls across pages */
  .chips,
  .common-numbers{
    page-break-inside:avoid;
  }

  .prediction-card,
  .lottery-group{
    page-break-inside:avoid;
    margin-bottom:1.2rem;
  }

  /* Make sure narrative prints full width */
  .narrative-card,
  [data-narrative]{
    max-width:100% !important;
  }
}


/* ============================================================
   WORLD-CLASS PREDICTION CARD SHELL
   Scoped to the prediction panel to avoid touching other cards
   ============================================================ */

.prediction-panel .prediction-card{
  display:flex;
  flex-direction:column;
  gap:0.45rem;
  padding:0.9rem 0.95rem 0.95rem;
  border-radius:14px;
  border:1px solid rgba(10,26,51,0.10);
  background:#ffffff;
  box-shadow:0 2px 10px rgba(10,26,51,0.06), 0 1px 3px rgba(10,26,51,0.04);
  transition:
    box-shadow .18s ease,
    transform .18s ease,
    border-color .18s ease;
}
.prediction-panel .prediction-card:hover{
  transform:translateY(-1px);
  box-shadow:0 12px 26px rgba(15,23,42,0.09);
  border-color:#c4d0ed;
}

/* Per-method subtle accent (Skip & Hit / AI / MCMC / Heatmap / SKAI) */
.prediction-panel .prediction-card.skip-hit{
  border-left:3px solid #1C66FF; /* Skip & Hit = blue */
}
.prediction-panel .prediction-card.ai-prediction{
  border-left:3px solid #16a34a; /* AI = green */
}
.prediction-panel .prediction-card.mcmc-prediction{
  border-left:3px solid #7c3aed;
}
.prediction-panel .prediction-card.heatmap{
  border-left:3px solid #f97316;
}
/* NEW: SKAI method accent */
.prediction-panel .prediction-card.skai-prediction{
  border-left:3px solid #06b6d4;
}

/* Header layout */
.prediction-panel .prediction-card .card-header{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:0.75rem;
  margin-bottom:0.2rem;
}
.prediction-panel .prediction-card .header-left{
  display:flex;
  flex-direction:column;
  gap:0.3rem;
}
.prediction-panel .prediction-card .header-badges{
  display:flex;
  flex-wrap:wrap;
  align-items:center;
  gap:0.35rem;
}

/* Source badge (AI, Skip & Hit, etc.) */
.prediction-panel .prediction-card .source-badge{
  display:inline-flex;
  align-items:center;
  gap:0.35rem;
  padding:0.25rem 0.65rem;
  border-radius:999px;
  background:rgba(28,102,255,0.08);
  border:1px solid rgba(28,102,255,0.22);
  font-size:.8rem;
  font-weight:700;
  color:#0A1A33;
  letter-spacing:.03em;
  max-width: 100%;
  word-break: break-word;
  line-height: 1.3;
}

/* Ensure header-left has enough space and doesn't cause overlap */
.prediction-panel .prediction-card .header-left {
  flex: 1;
  min-width: 0; /* Allow flexbox to shrink properly */
  margin-right: 0.5rem; /* Add spacing from right side buttons */
}

/* Timestamp / run label inside card header */
.timestamp-label{
  font-size:0.85rem;
  color:#7F8DAA;
  font-weight:600;
  line-height:1.35;
}
.timestamp-label .local-time{
  color:#0A1A33;
}

/* Compare checkbox label */
.prediction-panel .prediction-card .header-compare{
  display:inline-flex;
  align-items:center;
  gap:0.35rem;
  font-size:.82rem;
  color:#7F8DAA;
  cursor:pointer;
}
.prediction-panel .prediction-card .header-compare input[type="checkbox"]{
  width:1rem;
  height:1rem;
  border-radius:4px;
}

/* Settings toggle button */
.prediction-panel .prediction-card .settings-toggle{
  border:none;
  background:rgba(15,23,42,0.02);
  border-radius:999px;
  padding:0.25rem 0.75rem;
  font-size:.78rem;
  font-weight:600;
  letter-spacing:.02em;
  display:inline-flex;
  align-items:center;
  gap:0.25rem;
  cursor:pointer;
  color:#0A1A33;
  border:1px solid rgba(10,26,51,0.20);
  transition:
    background .15s ease,
    color .15s ease,
    border-color .15s ease,
    transform .12s ease;
}
.prediction-panel .prediction-card .settings-toggle:hover{
  background:#0A1A33;
  color:#f9fafb;
  border-color:#0A1A33;
  transform:translateY(-0.5px);
}

/* Settings panel: controlled by .open class via display:none/block !important (lines above) */
.prediction-panel .prediction-card .settings-panel.open{
  /* Additional styling when open */
}

/* Settings grid inside panel */
.prediction-panel .settings-grid{
  display:grid;
  grid-template-columns:repeat(2,minmax(0,1fr));
  gap:0.2rem 0.75rem;
  margin-top:0.1rem;
}
@media (max-width:768px){
  .prediction-panel .settings-grid{
    grid-template-columns:1fr;
  }
}
.prediction-panel .setting-row{
  font-size:.78rem;
  color:#374151;
  display:flex;
  justify-content:space-between;
  gap:0.4rem;
}

/* Drawn numbers & prediction pills */
.prediction-panel .drawn-pill,
.prediction-panel .match-pill,
.prediction-panel .no-match,
.prediction-panel .pred-pill{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-width:1.9rem;
  height:1.9rem;
  padding:0 .5rem;
  margin:0.08rem 0.18rem 0.08rem 0;
  border-radius:999px;
  border:1px solid #e5e7eb;
  font-size:.88rem;
  font-weight:600;
  letter-spacing:.01em;
  box-shadow:0 1px 1px rgba(15,23,42,0.03);
}

/* Actual drawn numbers (main) */
.prediction-panel .drawn-pill{
  background:#16a34a;
  border-color:#15803d;
  color:#ffffff;
}
.prediction-panel .drawn-pill.extra{
  background:#16a34a;
  border-color:#15803d;
  color:#ffffff;
}
/* Predicted numbers - non-hit */
.prediction-panel .no-match{
  background:#f3f4f6;
  color:#4b5563;
}

/* Predicted numbers - pending (draw not yet recorded) */
.prediction-panel .pred-pill{
  background:#e5e7eb;
  color:#6b7280;
}

/* Predicted numbers - hit */
.prediction-panel .match-pill{
  background:#dcfce7;
  border-color:#16a34a;
  color:#14532d;
}

/* Common overlap highlight (not drawn) - used ONLY for overlap/agreements, not the manual grid */
.prediction-panel .common-highlight{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-width:2.1rem;
  padding:0.15rem 0.5rem;
  border-radius:999px;
  font-size:0.82rem;
  font-weight:800;
  background:#1C66FF;              /* strong overlap signal */
  border:1px solid #0A1A33;
  color:#ffffff;
}

/* =========================================================
   Decision Builder - manual number pools (Main/Extra grids)
   ========================================================= */

/* Base grid pill (unselected): calm "button" */
/* =========================================================
   Decision Builder - manual number pools (Main/Extra grids)
   Scope to .lottery-group so it works everywhere in My LottoExpert
   ========================================================= */

.lottery-group .le-pick-pill{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-width:44px;
  height:40px;
  padding:0 10px;
  border-radius:999px;
  font-weight:800;
  font-size:0.9rem;
  color:#0A1A33;                   /* Deep Navy */
  background:#FFFFFF;
  border:1px solid #D7DEE9;
  box-shadow:0 1px 2px rgba(10,26,51,.08);
  cursor:pointer;
  user-select:none;
  transition:transform .08s ease, box-shadow .12s ease, background .12s ease, border-color .12s ease, color .12s ease;
}

.lottery-group .le-pick-pill:hover{
  border-color:#1C66FF;            /* SKAI Blue */
  box-shadow:0 4px 10px rgba(10,26,51,.14);
  transform:translateY(-1px);
}

.lottery-group .le-pick-pill:focus{
  outline:3px solid rgba(28,102,255,.35);
  outline-offset:2px;
}

/* SELECTED: obvious SKAI Blue + inner white ring */
.lottery-group .le-pick-pill.le-is-selected{
  background:#1C66FF !important;   /* SKAI Blue */
  border-color:#0A1A33 !important; /* Deep Navy */
  color:#FFFFFF !important;
  box-shadow:
    0 10px 18px rgba(10,26,51,.22),
    0 0 0 3px rgba(255,255,255,.92) inset; /* inner ring */
  transform:translateY(-1px);
}


/* Placeholder card styling */
.prediction-panel .prediction-placeholder{
  background:#f9fafb;
  border-style:dashed;
  border-color:#cbd5e1;
  box-shadow:none;
}
.prediction-panel .prediction-placeholder .btn-insight{
  margin-top:0.35rem;
}

/* Column layout refinements (scoped to prediction panel only) */
.prediction-panel .predictions-row{
  display:grid;
  grid-template-columns:repeat(3,minmax(0,1fr));
  gap:1rem;
}
@media (max-width:1024px){
  .prediction-panel .predictions-row{
    grid-template-columns:repeat(2,minmax(0,1fr));
  }
}
@media (max-width:640px){
  .prediction-panel .predictions-row{
    grid-template-columns:1fr;
  }
}
.prediction-panel .prediction-column{
  display:flex;
  flex-direction:column;
  gap:0.75rem;
}

/* (deduped: common-card alignment is handled by the base
   .prediction-card.common-card rules defined above) */



/* ============================================================
   SKAI Live Snapshot (makes the page feel "alive")
   ============================================================ */
.skai-live-snapshot{
  background: linear-gradient(180deg, #EFEFF5 0%, #FFFFFF 100%); /* Slate Mist */
  border: 1px solid rgba(10,26,51,0.10);
  border-radius: 14px;
  padding: 14px 14px 12px;
  margin: 10px 0 12px;
  box-shadow: 0 10px 26px rgba(10,26,51,0.08);
}

.skai-live-head{
  display:flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 10px;
  margin-bottom: 10px;
}
.skai-live-title{ color:#0A1A33; font-size: 1rem; }
.skai-live-sub{ color:#7F8DAA; font-size: .92rem; }

.skai-live-grid{
  display:grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px;
}
@media (max-width: 860px){
  .skai-live-grid{ grid-template-columns: 1fr; }
}

.skai-metric{
  background: #FFFFFF;
  border: 1px solid rgba(10,26,51,0.10);
  border-radius: 12px;
  padding: 12px;
}
.skai-metric-k{
  display:flex;
  align-items:center;
  gap:8px;
  color:#0A1A33;
  font-weight: 700;
  font-size:.92rem;
}
.skai-metric-v{
  color:#0A1A33;
  font-size: 1.08rem;
  margin-top: 6px;
}
.skai-metric-d{
  color:#7F8DAA;
  font-size: .88rem;
  margin-top: 2px;
  line-height:1.35;
}

.dot{
  width:10px;
  height:10px;
  border-radius:999px;
  display:inline-block;
}
.dot-agreement{ background:#1C66FF; }   /* SKAI Blue */
.dot-evidence{ background:#20C997; }    /* Success Green */
.dot-confidence{ background:#F5A623; }  /* Caution Amber */

.skai-mini-bars{
  display:flex;
  align-items:flex-end;
  gap:5px;
  height: 26px;
  margin-top: 8px;
}
.skai-mini-bar{
  flex: 1 1 0;
  height: 100%;
  background: rgba(10,26,51,0.08);
  border-radius: 6px;
  overflow:hidden;
}
.skai-mini-bar-fill{
  display:block;
  width: 100%;
  background: linear-gradient(135deg, #0A1A33 0%, #1C66FF 100%); /* Deep Horizon */
  border-radius: 6px;
  transition: height .25s ease;
}

.skai-confidence-meter{
  height: 10px;
  background: rgba(10,26,51,0.08);
  border-radius: 999px;
  overflow:hidden;
  margin-top: 10px;
}
.skai-confidence-fill{
  display:block;
  height: 100%;
  background: linear-gradient(135deg, #20C997 0%, #0A1A33 100%); /* Success Gradient */
  transition: width .25s ease;
}

.skai-live-cta{
  margin-top: 10px;
  color:#0A1A33;
  font-size: .92rem;
  line-height: 1.45;
}

/* ===== Run Timeline Drawer ===== */
.skai-run-timeline{
  margin: 10px 0 12px;
  border: 1px solid rgba(10,26,51,0.10);
  border-radius: 14px;
  background: #FFFFFF;
  box-shadow: 0 10px 26px rgba(10,26,51,0.06);
  overflow:hidden;
}

.skai-run-summary{
  list-style:none;
  cursor:pointer;
  padding: 12px 14px;
  display:flex;
  align-items:baseline;
  justify-content:space-between;
  gap: 10px;
  background: linear-gradient(135deg, #0A1A33 0%, #1C66FF 100%); /* Deep Horizon */
  color: #FFFFFF;
}
.skai-run-summary::-webkit-details-marker{ display:none; }

.skai-run-sum-title{ font-size: 1rem; }
.skai-run-sum-sub{ font-size: .9rem; opacity: .9; text-align:right; }

.skai-run-body{
  padding: 10px 12px 12px;
  background: linear-gradient(180deg, #EFEFF5 0%, #FFFFFF 100%);
}

.skai-run-row{
  display:grid;
  grid-template-columns: 110px 1fr 220px;
  gap: 10px;
  align-items:center;
  padding: 10px 10px;
  border: 1px solid rgba(10,26,51,0.08);
  border-radius: 12px;
  background: #FFFFFF;
  margin-top: 8px;
}
@media (max-width: 900px){
  .skai-run-row{ grid-template-columns: 110px 1fr; }
  .skai-run-metrics{ grid-column: 1 / -1; }
}
@media (max-width: 520px){
  .skai-run-row{ grid-template-columns: 1fr; }
  .skai-run-when{ justify-self:start; }
}

.skai-run-when{
  color:#7F8DAA;
  font-size: .88rem;
  white-space:nowrap;
}

.skai-run-meta{
  display:flex;
  flex-wrap:wrap;
  gap: 6px;
  align-items:center;
}

.skai-chip{
  display:inline-flex;
  align-items:center;
  padding: 0.1rem 0.55rem;
  border-radius: 999px;
  font-size: .78rem;
  font-weight: 700;
  border: 1px solid rgba(10,26,51,0.10);
  background: #FFFFFF;
  color:#0A1A33;
}

.skai-chip-mode{
  background: rgba(28,102,255,0.10);
  border-color: rgba(28,102,255,0.25);
  color:#0A1A33;
}
.skai-chip-level{
  background: rgba(32,201,151,0.10);
  border-color: rgba(32,201,151,0.25);
  color:#0A1A33;
}

.skai-run-metrics{
  display:flex;
  justify-content:flex-end;
  gap: 10px;
  flex-wrap:wrap;
}
.skai-run-metric{
  display:flex;
  align-items:center;
  gap: 6px;
  padding: 0.25rem 0.55rem;
  border-radius: 10px;
  border: 1px solid rgba(10,26,51,0.08);
  background: rgba(10,26,51,0.02);
  color:#0A1A33;
  font-size: .85rem;
}
.skai-run-metric .k{ color:#7F8DAA; font-weight:700; }
.skai-run-metric .v{ font-weight:800; }

.skai-run-timeline[open] .skai-run-summary{
  filter: brightness(1.02);
}




@media (prefers-reduced-motion: reduce){
  .skai-mini-bar-fill,
  .skai-confidence-fill{
    transition:none !important;
  }
}

[[/style]]




<!-- ============ PLACEMENT HEATMAP (Mains) ============ -->
<?php if ($__mode === 'post'): ?>
[[div class="card placement-wrap"]]
  [[div class="card-header"]]
    [[h3 class="title"]]Rank Accuracy Profile (Mains)[[/h3]]
  [[/div]]

  [[div class="helper" style="margin:.5rem 0 1rem; font-size:.95rem; color:#2c3e50; line-height:1.5;"]]
    [[strong]]What this shows:[[/strong]]
    Each [[em]]row[[/em]] is one method (Skip &amp; Hit, AI, MCMC, Heatmap).
    Each [[em]]column[[/em]] is a rank position: #1 is your first choice, #20 is a later pick.
    The numbers in the cells are [[em]]how many times the actual winning numbers landed in that rank[[/em]] across all saved runs for this draw.
    [[br]][[br]]
    [[strong]]How to read it:[[/strong]]
    [[br]]
    � The [[strong]]red side on the left (low rank numbers)[[/strong]] shows early picks.  
    � The [[strong]]blue side on the right (high rank numbers)[[/strong]] shows later picks.  
    � Darker cells mean [[em]]more hits[[/em]] at that rank.  
    � Gold-outlined cells mark each method's current [[em]]"sweet-spot" ranks[[/em]].
    [[br]][[br]]


    [[strong]]Goal:[[/strong]]
    You want your best methods to stack hits in the #1-#5 columns early instead of "finding them by accident" in the late ranks.
  [[/div]]


  <?php


  /* ---------- PHP helpers for color & readability ---------- */
if (!function_exists('hue2rgb')) {
    function hue2rgb($p, $q, $t) {
        if ($t < 0) $t += 1;
        if ($t > 1) $t -= 1;
        if ($t < 1/6) return $p + ($q - $p) * 6 * $t;
        if ($t < 1/2) return $q;
        if ($t < 2/3) return $p + ($q - $p) * (2/3 - $t) * 6;
        return $p;
    }
}
if (!function_exists('hslToHex')) {
    function hslToHex(float $h, float $s, float $l): string {
        $h = fmod(max(0, min(360, $h)), 360) / 360.0;
        $s = max(0, min(100, $s)) / 100.0;
        $l = max(0, min(100, $l)) / 100.0;

        $r = $l; $g = $l; $b = $l;
        if ($s != 0) {
            $q = ($l < 0.5) ? ($l * (1 + $s)) : ($l + $s - $l * $s);
            $p = 2 * $l - $q;
            $r = hue2rgb($p, $q, $h + 1/3);
            $g = hue2rgb($p, $q, $h);
            $b = hue2rgb($p, $q, $h - 1/3);
        }
        return sprintf('#%02X%02X%02X',
            (int)round($r * 255),
            (int)round($g * 255),
            (int)round($b * 255)
        );
    }
}
  if (!function_exists('textColorForHex')) {
    function textColorForHex(string $hex): array {
      $hex = ltrim($hex, '#');
      if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
      $r = hexdec(substr($hex, 0, 2));
      $g = hexdec(substr($hex, 2, 2));
      $b = hexdec(substr($hex, 4, 2));
      $lum = (0.2126*$r + 0.7152*$g + 0.0722*$b) / 255;
      if ($lum < 0.55) { return ['#FFFFFF', '0 1px 0 rgba(0,0,0,.35)']; }
      return ['#111111', '0 1px 0 rgba(255,255,255,.35)'];
    }
  }
  ?>

  [[div class="placement-grid"]]
    [[table]]
      [[thead]]
        [[tr]]
          [[th style="text-align:left;"]]Method[[/th]]
          <?php for ($c=1; $c<=$__maxRankMain; $c++): ?>
            [[th]]<?php echo $c; ?>[[/th]]
          <?php endfor; ?>
          [[th]]Row Total[[/th]]
        [[/tr]]
      [[/thead]]
      [[tbody]]
        <?php
          // For slight intensity boost, we still compute a global max (only affects lightness tweak).
          $globalMax = 0;
          foreach ($methodKeys as $__m) {
            $maxInRow = max($placementStats[$__m]['main'] ?: [0]);
            if ($maxInRow > $globalMax) $globalMax = $maxInRow;
          }
          if ($globalMax <= 0) $globalMax = 1;

          foreach ($methodKeys as $__m):
            $row = $placementStats[$__m]['main'];
            $rowTotal = array_sum($row);

            // Peak columns (optional: keeps your gold outline logic)
            arsort($row);
            $peakCols = array_slice(array_keys($row), 0, 2);
$peakCols = array_values(array_filter($peakCols, function ($k) use ($placementStats, $__m) {
    return !empty($placementStats[$__m]['main'][$k]);
}));
        ?>
        [[tr]]
          [[td style="text-align:left; font-weight:700;"]]
            <?php echo htmlspecialchars($labelShort[$__m], ENT_QUOTES); ?>
          [[/td]]

          <?php for ($c=1; $c<=$__maxRankMain; $c++):
            $val = (int)($row[$c] ?? 0);

            if ($val === 0) {
              $cls   = 'zero';
              $style = '';
            } else {
              // === RANK ? COLOR mapping ===
              // rank #1 (leftmost) -> Hue 0 (red) ; rank #20 (rightmost) -> Hue 240 (blue)
              $t    = ($__maxRankMain > 1) ? (($c - 1) / ($__maxRankMain - 1)) : 0; // 0..1
              $hue  = 0 + (240 * $t);                // 0=red ? 240=blue

              // Slight intensity boost by count: higher counts = a bit darker (lower lightness)
              $ratio = $val / $globalMax;            // 0..1
              $light = 52 - (min(1, max(0, $ratio)) * 8); // 52%..44%

              $bg    = hslToHex($hue, 90, $light);
              [$txt, $shadow] = textColorForHex($bg);
              $cls   = '';
              $style = 'background:'.$bg.';color:'.$txt.';font-weight:700;text-shadow:'.$shadow.';';
            }

            $peakCls = in_array($c, $peakCols, true) ? ' cell-peak' : '';
          ?>
            [[td class="<?php echo $cls . $peakCls; ?>" style="<?php echo $style; ?>" title="Hits at rank #<?php echo $c; ?>: <?php echo $val; ?>"]]
              <?php echo $val; ?>
            [[/td]]
          <?php endfor; ?>

          [[td style="font-weight:700;"]]
            <?php echo (int)$rowTotal; ?>
          [[/td]]
        [[/tr]]
        <?php endforeach; ?>
      [[/tbody]]
    [[/table]]
  [[/div]]

  <!-- Optional mini legend -->
  [[div style="margin-top:.35rem; font-size:.85rem; color:#444;"]]
    [[span style="display:inline-block; width:16px; height:10px; background:#FF0000; margin-right:.35rem;"]][[/span]] Rank #1 (hot)
    &nbsp;?&nbsp;
    [[span style="display:inline-block; width:16px; height:10px; background:#0000FF; margin:0 .35rem 0 .55rem;"]][[/span]] Rank #<?php echo $__maxRankMain; ?> (cool)
    &nbsp;�&nbsp; Gold outline = peak columns for that method
  [[/div]]
  
  <?php
  // ===== Dynamic takeaway: best method, tie-break by earliest (lowest) hit rank =====
  // We scan each method's placement row, count total hits, and track how early
  // (low rank) the first hit appears. Ties in total hits are broken by who hits earlier.
  $best = [
      'method'   => null,
      'total'    => -1,
      'bestRank' => PHP_INT_MAX,
  ];
  $perMethodSummaries = [];

  foreach ($methodKeys as $__m) {
      // Main placement row for this method
      $row       = $placementStats[$__m]['main'] ?? [];
      $totalHits = array_sum($row);

      // Find earliest (lowest) rank where this method has at least one hit
      $earliest = PHP_INT_MAX;
      for ($r = 1; $r <= $__maxRankMain; $r++) {
          if (!empty($row[$r])) {
              $earliest = $r;
              break;
          }
      }

      // Find latest (highest) rank where this method has a hit
      $latest = 0;
      for ($r = $__maxRankMain; $r >= 1; $r--) {
          if (!empty($row[$r])) {
              $latest = $r;
              break;
          }
      }

      // Update "best" method:
      // 1) Highest total hits wins
      // 2) If tied, the method that hits earlier (lower rank) wins
      if (
          $totalHits > $best['total']
          || ($totalHits === $best['total'] && $earliest < $best['bestRank'])
      ) {
          $best['method']   = $__m;
          $best['total']    = $totalHits;
          $best['bestRank'] = $earliest;
      }

      // Human-friendly range text, e.g. "#2-#11" or "-" if no hits
      $rangeText = ($earliest !== PHP_INT_MAX && $latest > 0)
          ? ('#' . $earliest . '-#' . $latest)
          : '-';

      $label = $labelShort[$__m] ?? $__m;
      $perMethodSummaries[] = $label . ': total ' . (int) $totalHits . ' hits, range ' . $rangeText;
  }

  // Compose the headline + advice based on how early the best method hits
  $bestLabel    = $best['method'] ? ($labelShort[$best['method']] ?? $best['method']) : '-';
  $bestTotal    = max(0, (int) $best['total']);
  $bestRankText = ($best['bestRank'] === PHP_INT_MAX) ? 'n/a' : '#' . $best['bestRank'];

  if ($best['bestRank'] !== PHP_INT_MAX && $best['bestRank'] <= 5) {
      $advice = 'Great: your best method is hitting in the red zone (#1-#5). Keep tuning to push more hits into #1-#3.';
  } elseif ($best['bestRank'] !== PHP_INT_MAX && $best['bestRank'] <= 10) {
      $advice = 'Decent: hits are appearing mid-rank. Try adjusting settings to pull more into #1-#5.';
  } else {
      $advice = 'Heads-up: hits are mostly late (blue side). Explore other settings to improve early-rank placement.';
  }
  ?>

[[div class="helper" style="margin-top:.75rem; padding:.85rem 1.05rem; background:#f7fbff; border:1px solid #dfe6f2; border-radius:8px;"]]
  [[div style="font-weight:700; margin-bottom:0.25rem; font-size:0.95rem; color:#1f2a3a; letter-spacing:0.01em;"]]
    Rank Accuracy Insight
  [[/div]]

  [[div style="font-size:0.9rem; margin-bottom:0.35rem;"]]
    [[strong]]Best performer:[[/strong]]
    [[strong]]<?php echo htmlspecialchars($bestLabel, ENT_QUOTES); ?>[[/strong]]
    with [[strong]]<?php echo $bestTotal; ?> total hits[[/strong]]
    (earliest winning rank [[strong]]<?php echo htmlspecialchars($bestRankText, ENT_QUOTES); ?>[[/strong]]).
  [[/div]]

  [[div style="font-size:0.9rem; margin-bottom:0.4rem; color:#34495e; line-height:1.45;"]]
    <?php echo htmlspecialchars($advice, ENT_QUOTES); ?>
  [[/div]]

  [[div style="font-size:0.85rem; color:#4a5568; line-height:1.4;"]]
    [[strong]]Per-method pattern:[[/strong]]
    [[em]]<?php echo htmlspecialchars(implode('  �  ', $perMethodSummaries), ENT_QUOTES); ?>[[/em]]
    [[br]]
    [[span style="opacity:0.9;"]]
      Tip: Aim to match more of your chosen numbers where methods score early (ranks #1-#5), and use later ranks as secondary numbers.
    [[/span]]
  [[/div]]
[[/div]]
<?php else: ?>
[[div class="card placement-wrap"]]
  [[div class="card-header"]]
    [[h3 class="title"]]Rank Accuracy Profile (Mains)[[/h3]]
  [[/div]]
  [[div style="padding:.75rem 1rem; font-size:.9rem; color:#64748b; font-style:italic;"]]
    Draw results not yet recorded &mdash; rank accuracy will be available after the draw is completed.
  [[/div]]
[[/div]]
<?php endif; ?>

[[/div]]

      <!-- ============ COMMON ACROSS (world-class agreement bar) ============ -->
      [[div class="prediction-card common-card"
            data-common-bar
            data-has-extra="<?php
              // Use lottery_skip_config.json (keyed by gameId) as the canonical source.
              $gid = (string) ($g['game_id'] ?? '');
              $hasExtra = 0;
              $mainMax  = 0;
              $extraMax = 0;
              $extraCnt = 0;

              $cfgFile = JPATH_ROOT . '/lottery_skip_config.json';
              if (is_file($cfgFile)) {
                $cfg = json_decode((string) file_get_contents($cfgFile), true);
                if (json_last_error() === JSON_ERROR_NONE && !empty($cfg['lotteries'][$gid]['lotteryConfig'])) {
                  $lc = $cfg['lotteries'][$gid]['lotteryConfig'];

                  $mainMax  = (int) ($lc['max_main_ball_number'] ?? 0);
                  $hasExtra = !empty($lc['has_extra_ball']) ? 1 : 0;
                  $extraMax = (int) ($lc['max_extra_ball_number'] ?? 0);

                  // Supports games with 2 extras (e.g., EuroMillions)
                  $extraCnt = (int) ($lc['num_extra_balls_drawn'] ?? 0);
                  if ($extraCnt < 0) { $extraCnt = 0; }
                }
              }

              echo $hasExtra ? '1' : '0';
            ?>"
            data-main-max="<?php echo (int) ($mainMax ?? 0); ?>"
            data-extra-max="<?php echo (int) ($extraMax ?? 0); ?>"
            data-extra-count="<?php echo (int) ($extraCnt ?? 0); ?>"
      ]]
        [[div class="card-header" style="display:flex; align-items:center; gap:0.75rem; flex-wrap:wrap; justify-content:flex-start;"]]
          [[div style="display:flex; flex-direction:column; gap:0.15rem;"]]
            [[div style="display:flex; align-items:baseline; gap:0.5rem; flex-wrap:wrap;"]]
              [[strong style="font-size:1.1rem; color:#1f2a3a; letter-spacing:0.01em;" data-common-title]]
                Numbers in Common
              [[/strong]]
              [[span class="common-count" style="font-weight:600; font-size:0.95rem; color:#1f618d;"]]
                (Mains: 0, Extra: 0, Total: 0)
              [[/span]]
            [[/div]]
            [[div style="font-size:0.9rem; color:#576574; max-width:42rem; line-height:1.4;" data-common-desc]]
              Numbers that appear across multiple selected methods. When methods agree, the signal is stronger.
            [[/div]]
          [[/div]]
        [[/div]]

        [[div class="common-numbers"
              style="padding:0.85rem 1rem 1rem; display:flex; flex-wrap:wrap; gap:0.5rem; border-top:1px solid #e3e7ef;"]]
          <!-- JS will inject overlaps here -->
        [[/div]]



        <!-- REMOVED: Field Coverage section
             Reason: Redundant and not useful.
             This section and its associated calculations have been removed entirely.
             Original location: lines ~3488-3495
        -->

        [[div style="padding:0 1rem 0.85rem; border-top:1px dashed #e3e7ef; margin-top:0.15rem; font-size:0.78rem; color:#4b5563; display:flex; flex-wrap:wrap; gap:0.5rem 0.75rem; align-items:center;"]]
          [[span style="font-weight:600; text-transform:uppercase; letter-spacing:0.04em; font-size:0.75rem; color:#6b7280;"]]
            Number Tags:
          [[/span]]
          [[span class="btag btag-hit"]]HIT[[/span]]
          [[span style="font-size:0.75rem;"]]Matched actual draw[[/span]]

          [[span class="btag btag-ovl"]]OVL[[/span]]
          [[span style="font-size:0.75rem;"]]Appears in 2+ methods[[/span]]

          [[span class="btag btag-ai"]]NN[[/span]]
          [[span style="font-size:0.75rem;"]]Neural network[[/span]]

          [[span class="btag btag-sh"]]PA[[/span]]
          [[span style="font-size:0.75rem;"]]Pattern analysis[[/span]]

          [[span class="btag btag-mm"]]PS[[/span]]
          [[span style="font-size:0.75rem;"]]Probability sampling[[/span]]

          [[span class="btag btag-hm"]]FM[[/span]]
          [[span style="font-size:0.75rem;"]]Frequency map[[/span]]

          [[span class="btag btag-sk"]]SKAI[[/span]]
          [[span style="font-size:0.75rem;"]]Hybrid model[[/span]]
        [[/div]]
      [[/div]]

      <!-- Narrative: What Stands Out -->
      [[div class="narrative-header" style="margin:1.5rem 1rem 1rem; padding:1rem; background:linear-gradient(135deg, #f8fafc 0%, #e7f3ff 100%); border-left:3px solid #1C66FF; border-radius:8px;"]]
        [[h3 style="margin:0 0 0.5rem 0; font-size:1.1rem; font-weight:600; color:#0A1A33;"]]
          What to Look For
        [[/h3]]
        [[p style="margin:0; font-size:0.95rem; line-height:1.6; color:#334155;"]]
          Compare predictions across methods below. Numbers that appear in multiple methods are agreement signals. Where methods diverge shows the range of possibilities. Use the comparison tools to find common numbers.
        [[/p]]
      [[/div]]

      <!-- -- Three-column layout: Skip & Hit | AI | MCMC -- -->
      [[div class="predictions-row"]]

        <?php
          // 1) Group saved runs by source
          $predsBySource = [];
          foreach ($g['preds'] as $s) {
            $predsBySource[$s->source][] = $s;
          }
        ?>

        <?php foreach ($modules as $mod):
          $moduleKey     = $mod['key'];
          $moduleLabel   = $mod['label'];
          $moduleIcon    = $mod['icon'];
          $moduleTooltip = $mod['tooltip'] ?? '';
          $runs          = $predsBySource[$moduleKey] ?? [];
        ?>
        [[div class="prediction-column"]]

          <?php if (!empty($runs)): ?>

            <?php foreach ($runs as $s):
$tsRaw = $s->generated_at ?: ($s->date_saved ?: ($g['draw_date'] ?? ''));
$ts    = strtotime((string) $tsRaw);
$when  = $ts ? date('c', $ts) : (string) $tsRaw; // ISO8601 for JS local-time conversion

              // -- Single source-of-truth: same draw lookup + field extraction as CRR/Best-Opps --
              // Pre-compute scored data (draw available, drawn numbers, hit counts)
              $__cardPredMain = array_values(array_filter(
                  array_map('intval', explode(',', (string)$s->main_numbers)),
                  static function($n){ return $n !== 0; }
              ));
              $__cardPredMain = array_slice($__cardPredMain, 0, 20);
              $__cardPredExtra = 0;
              if (!empty($s->extra_ball_numbers)) {
                  $__tmp = array_values(array_filter(array_map('intval', explode(',', (string)$s->extra_ball_numbers))));
                  if (!empty($__tmp)) { $__cardPredExtra = (int)$__tmp[0]; }
              }
              $__cardLotCfg = $configData['lotteries'][$g['game_id']] ?? [];
              $__cardScored = resolveDrawNumbers($db, $g['game_id'], $g['draw_date'], $__cardLotCfg, $drawMap);
              // Convenience aliases used by display logic below
              $drawMain  = $__cardScored['main'];
              $drawExtra = $__cardScored['extra'];

              $fields   = getDrawFields($g['game_id']);
              $mainCols = $fields['main']  ?? [];
              $extraCol = $fields['extra'] ?? null;

              // Game-aware label for the extra ball (falls back to "Extra Ball")
              $extraLabel = 'Extra Ball';
              if (!empty($configData['lotteries'][$g['game_id']]['lotteryConfig']['extra_ball_label'])) {
                  $extraLabel = (string) $configData['lotteries'][$g['game_id']]['lotteryConfig']['extra_ball_label'];
              } else {
                  // Heuristics by game name (optional fallback)
                  $lname = strtolower((string) ($g['lottery_name'] ?? ''));
                  if (strpos($lname, 'powerball') !== false)      $extraLabel = 'Powerball';
                  elseif (strpos($lname, 'mega') !== false)       $extraLabel = 'Mega Ball';
                  elseif (strpos($lname, 'lucky star') !== false) $extraLabel = 'Lucky Star';
                  elseif (strpos($lname, 'star') !== false)       $extraLabel = 'Star';
                  elseif (strpos($lname, 'bonus') !== false)      $extraLabel = 'Bonus Ball';
              }

            ?>

            <?php
              // Best Rank Strength highlight: check if this run is the winner for this lottery
              $__cardLid       = (int)$g['lottery_id'];
              $__bestRankRunId = isset($bestOpps[$__cardLid]) ? (int)$bestOpps[$__cardLid]['best_rank']['run_id'] : 0;
              $__isBestRank    = ($__bestRankRunId > 0 && (int)$s->id === $__bestRankRunId);
            ?>
[[div id="pred-card-<?php echo (int)$s->id; ?>"
     class="prediction-card <?php echo str_replace('_','-',$s->source); ?><?php if ($__isBestRank): ?> skai-best-rank-highlight<?php endif; ?>"
     data-set-id="<?php echo (int)$s->id; ?>"
     data-run-id="<?php echo (int)$s->id; ?>"
     data-source="<?php echo htmlspecialchars($s->source, ENT_QUOTES); ?>"
     data-pure="<?php echo ($s->source==='skip_hit' && (int)($s->pure_mode ?? 0)===1) ? '1' : '0'; ?>"
     data-pill-main="<?php
       $__pillMain = array_values(array_unique(array_filter(
         array_map('intval', explode(',', (string)$s->main_numbers)),
         static function($n){ return $n !== 0; }
       )));
       $__pillMain = array_slice($__pillMain, 0, 20);
       echo htmlspecialchars(implode(',', $__pillMain), ENT_QUOTES);
     ?>"
     data-pill-extra="<?php
       $__pillExtra = (!empty($s->extra_ball_numbers))
         ? array_values(array_unique(array_filter(
             array_map('intval', explode(',', (string)$s->extra_ball_numbers)),
             static function($n){ return $n !== 0; }
           )))
         : [];
       $__pillExtra = array_slice($__pillExtra, 0, 5);
       echo htmlspecialchars(implode(',', $__pillExtra), ENT_QUOTES);
     ?>"]]


[[div class="card-header"]]
  [[div class="header-left"]]
    [[div class="header-badges"]]
       [[span class="source-badge" title="<?php echo htmlspecialchars($moduleTooltip, ENT_QUOTES); ?>"]]
        <?php
          // Only show the icon if it is non-empty, then show the label
          if (!empty($moduleIcon)) {
              echo $moduleIcon . ' ';
          }
          echo htmlspecialchars($moduleLabel, ENT_QUOTES);
        ?>
      [[/span]]
      <?php if ($__isBestRank): ?>
        [[span class="skai-best-rank-ribbon" title="This run had the most hits appearing earliest in the list"]]&#9733; Most Hits Ranking[[/span]]
      <?php endif; ?>

      <?php
        // Default: use the stored label
        $displayLabel = '';
        if (!empty($s->label)) {
            $displayLabel = (string) $s->label;
        }

        // For hybrid model (SKAI), build a consistent label
        if ($s->source === 'skai_prediction') {
            // Resolve a lottery name just like our header logic
            $lotName = '';
            if (!empty($g['lottery_name'])) {
                $lotName = $g['lottery_name'];
            } elseif (!empty($g['game_name'])) {
                $lotName = $g['game_name'];
            } elseif (!empty($s->lottery_name)) {
                $lotName = $s->lottery_name;
            }


            if ($lotName !== '') {
                $displayLabel = $lotName . ' Hybrid Analysis';
            } else {
                $displayLabel = 'Hybrid Analysis';
            }
        }

        if ($displayLabel !== ''):
      ?>
        [[span class="timestamp-label" data-timestamp="<?php echo htmlspecialchars($when, ENT_QUOTES); ?>"]]
          <?php echo htmlspecialchars($displayLabel, ENT_QUOTES); ?> - [[span class="local-time"]]<?php echo htmlspecialchars($when, ENT_QUOTES); ?>[[/span]]
        [[/span]]
      <?php endif; ?>

    [[/div]]
    
    <?php
  // Show PURE-SKIP badge only for Skip & Hit that were saved with pure_mode=1
  $isPure = (isset($s->pure_mode) && (int)$s->pure_mode === 1);
  if ($s->source === 'skip_hit' && $isPure):
?>
  [[span class="badge pure-badge" title="Generated with Pure Skip mode"]]
    PURE-SKIP
  [[/span]]

  <?php if (!empty($s->pure_uid)): ?>
    [[span class="badge gray" title="Unique identifier for this PURE run"]]
      ID: <?php echo htmlspecialchars(substr((string)$s->pure_uid, 0, 8), ENT_QUOTES); ?>
    [[/span]]
  <?php endif; ?>
<?php endif; ?>

    [[label class="header-compare"]]
      [[input
        type="checkbox"
        class="card-compare-checkbox"
        value="<?php echo (int)$s->id; ?>"
        aria-label="Compare <?php echo htmlspecialchars($moduleLabel, ENT_QUOTES); ?> prediction for <?php echo htmlspecialchars($g['lottery_name'] ?? '', ENT_QUOTES); ?>"
      ]]
      Compare
    [[/label]]
  [[/div]]

[[button
  class="settings-toggle"
  type="button"
  title="Show details"
  aria-expanded="false"
  aria-controls="settings-panel-<?php echo (int)$s->id; ?>"
]]
  Details
[[/button]]

[[/div]]
              <?php if ($__cardScored['has_draw']): ?>
                [[div]]Drawn:
                  <?php foreach ($drawMain as $__dv): ?>
                    [[span class="drawn-pill"]]<?php echo (int)$__dv; ?>[[/span]]
                  <?php endforeach; ?>
                [[/div]]

                <?php if (!empty($drawExtra)): ?>
                  [[div]]<?php echo htmlspecialchars($extraLabel, ENT_QUOTES); ?>:
                    <?php foreach ($drawExtra as $__dv): ?>
                      [[span class="drawn-pill extra"]]<?php echo (int)$__dv; ?>[[/span]]
                    <?php endforeach; ?>
                  [[/div]]
                <?php endif; ?>

              <?php else: ?>
                [[div]]Drawn: [[em]]Upcoming[[/em]][[/div]]
              <?php endif; ?>

              [[div]]Your Numbers:
                <?php
                  // Use same normalized predMain already computed above
                  $predMain = $__cardPredMain;
                  foreach ($predMain as $n):
                    if ($__cardScored['has_draw']) {
                      $isHit = in_array($n, $drawMain, true);
                      $cls   = $isHit ? 'match-pill' : 'no-match';
                    } else {
                      $cls = 'pred-pill';
                    }
                ?>
                  [[span class="<?php echo $cls; ?>"]]
                    <?php echo $n; ?>
                  [[/span]]
                <?php endforeach; ?>
              [[/div]]

                            <?php if (!empty($s->extra_ball_numbers)): ?>
                [[div]]<?php echo htmlspecialchars($extraLabel, ENT_QUOTES); ?>:
                  <?php
                    $predExtra = array_slice(
                      array_map('intval', explode(',', (string)$s->extra_ball_numbers)),
                      0,
                      5
                    );
                    foreach ($predExtra as $e):
                      if ($__cardScored['has_draw']) {
                        $isHit = in_array($e, $drawExtra, true); // compare only against extra(s)
                        $cls   = $isHit ? 'match-pill extra' : 'no-match extra';
                      } else {
                        $cls = 'pred-pill extra';
                      }
                  ?>
                    [[span class="<?php echo $cls; ?>"]]
                      <?php echo $e; ?>
                    [[/span]]
                  <?php endforeach; ?>
                [[/div]]
              <?php endif; ?>

[[div
  id="settings-panel-<?php echo (int)$s->id; ?>"
  class="settings-panel"
  role="region"
  aria-label="Saved settings for <?php echo htmlspecialchars($moduleLabel, ENT_QUOTES); ?> prediction for <?php echo htmlspecialchars($g['lottery_name'] ?? '', ENT_QUOTES); ?>"
  style="margin-top:auto; font-size:.75rem; line-height:1.4; color:#444; padding-top:0.5rem;"
  aria-hidden="true"
]]

<?php
  // normalize your source just in case
  $src = trim((string)$s->source);

  // pick the labels you want
  $settingLabels = [];
  if ($src === 'ai_prediction') {
    $settingLabels = [
      'epochs'              => 'Epochs',
      'batch_size'          => 'Batch',
      'dropout_rate'        => 'Dropout',
      'learning_rate'       => 'LR',
      'activation_function' => 'ActFn',
      'hidden_layers'       => 'Layers',
      'recency_decay'       => 'Recency Decay',
    ];
  } elseif ($src === 'skai_prediction') {
    $settingLabels = [
      // Core neural hyperparameters
      'epochs'              => 'Epochs',
      'batch_size'          => 'Batch',
      'dropout_rate'        => 'Dropout',
      'learning_rate'       => 'Learning rate',
      'activation_function' => 'Activation',
      'hidden_layers'       => 'Hidden layers',
      'recency_decay'       => 'Recency decay',
      // Windows
      'skai_window_size'    => 'SKAI window',
      'skip_window'         => 'Skip window',
      'draws_used'          => 'Draws used',
      'tuned_window'        => 'Tuned window',
      // Behavior & sampling
      'sampling_temperature'=> 'Sampling temp',
      'diversity_penalty'   => 'Diversity penalty',
      'gap_scale'           => 'Gap scale',
      // Mode / automation
      'skai_run_mode'       => 'Run mode',
      'auto_tune'           => 'Auto-tune',
      'tune_used'           => 'Tune used',
      'best_window'         => 'Best window',
      // Output size
      'skai_top_n_numbers'  => 'Top N numbers',
      'skai_top_n_combos'   => 'Top N combos',
    ];
} elseif ($src === 'mcmc_prediction') {
    $settingLabels = [
      'walks'     => 'Walks',
      'burn_in'   => 'Burn-in',
      'laplace_k' => 'Laplace K',
      'decay'     => 'Decay',
      'chain_len' => 'Chain Len',
    ];
} elseif ($src === 'skip_hit') {
    $settingLabels = [
      'draws_analyzed' => 'Draws',
      'freq_weight'    => 'Freq Wt',
      'skip_weight'    => 'Skip Wt',
      'hist_weight'    => 'Hist Wt',
      // pseudo-field we'll render manually just below:
      // 'pure_mode'   => 'Pure Mode',
    ];
}
 elseif ($src === 'heatmap') {
    // Heatmap saves just numbers + label; no expert settings.
    $settingLabels = [];
}

  // timestamp (safe against invalid dates) - output ISO format for JS conversion
  $__ts = strtotime((string)($s->date_saved ?? ''));
  $__tsStr = $__ts ? date('c', $__ts) : (string)($s->date_saved ?? '');
  echo '[[div style="margin-bottom: .3rem;" class="timestamp-container" data-timestamp="' . htmlspecialchars($__tsStr, ENT_QUOTES) . '"]][[em class="local-time"]]'
     . htmlspecialchars($__tsStr, ENT_QUOTES)
     . '[[/em]][[/div]]';

  // [SKAI-FIX-02] Method/source always shown so panel is never empty
  $__methodLabel = $__methodLabels[$src] ?? $src;
  echo '[[div class="setting-row"]]'
     . '[[strong]]Method:[[/strong]] '
     . htmlspecialchars($__methodLabel, ENT_QUOTES)
     . '[[/div]]';

// now render the grid
if (!empty($settingLabels)) {
  echo '[[div class="settings-grid"]]';

  // Prefer JSON settings payload when present (prevents "Epochs = 0" when values are saved in JSON).
  $rawSettingsJson = null;
  foreach (['settings_json','settings','params_json','params','meta_json','config_json'] as $__k) {
    if (isset($s->$__k) && $s->$__k !== null && $s->$__k !== '') {
      $rawSettingsJson = (string)$s->$__k;
      break;
    }
  }
  $decodedSettings = [];
  if ($rawSettingsJson) {
    $decodedSettings = json_decode($rawSettingsJson, true);
    if (!is_array($decodedSettings)) $decodedSettings = [];
  }

  foreach ($settingLabels as $prop => $settingLabel) {
    $val = $s->$prop ?? null;

    // If the DB column is empty/0 but JSON has a real value, use JSON.
    if (($val === null || $val === '' || $val === 0 || $val === '0') && array_key_exists($prop, $decodedSettings)) {
      $val = $decodedSettings[$prop];
    }

    // [SKAI-FIX-02] Always show row; show (not saved) when value is absent
    // Note: '<em>(not saved)</em>' is a hardcoded constant, not user data - safe to echo raw.
    $__displayVal = ($val !== null && $val !== '') ? htmlspecialchars((string)$val, ENT_QUOTES) : '<em>(not saved)</em>';
    echo '[[div class="setting-row"]]'
       . '[[strong]]' . htmlspecialchars($settingLabel, ENT_QUOTES) . ':[[/strong]] '
       . $__displayVal
       . '[[/div]]';
  }

  // SKAI "mode" (Profile / Strategy / Blend) - show if present in JSON
  if ($src === 'skai_prediction') {
    $profile  = $decodedSettings['profile']  ?? ($decodedSettings['training_style'] ?? null);
    $strategy = $decodedSettings['strategy'] ?? null;
    $blend    = $decodedSettings['blend']    ?? ($decodedSettings['blend_percent'] ?? null);

    if ($profile || $strategy || $blend !== null) {
      echo '[[div class="setting-row"]]'
         . '[[strong]]Training style:[[/strong]] '
         . htmlspecialchars((string)($profile ?: '-'), ENT_QUOTES)
         . '[[/div]]';

      echo '[[div class="setting-row"]]'
         . '[[strong]]Skip vs AI strategy:[[/strong]] '
         . htmlspecialchars((string)($strategy ?: '-'), ENT_QUOTES)
         . '[[/div]]';

      echo '[[div class="setting-row"]]'
         . '[[strong]]Blend:[[/strong]] '
         . htmlspecialchars((string)($blend !== null ? $blend : '-'), ENT_QUOTES)
         . '[[/div]]';
    }
  }

  // Append Pure Mode details for Skip & Hit runs
  if ($src === 'skip_hit') {
    $pureMode = (int)($s->pure_mode ?? 0);
    echo '[[div class="setting-row"]]'
       . '[[strong]]Pure Mode:[[/strong]] '
       . ($pureMode === 1 ? 'Yes' : 'No')
       . '[[/div]]';
  }

  // Append a human-friendly blend summary for SKAI runs
  if ($src === 'skai_prediction') {
    $blendSkip = isset($s->skai_blend_skip_pct) ? (float) $s->skai_blend_skip_pct : null;
    $blendAi   = isset($s->skai_blend_ai_pct)   ? (float) $s->skai_blend_ai_pct   : null;

    if ($blendSkip !== null || $blendAi !== null) {
        if ($blendSkip !== null && $blendAi === null) {
            $blendAi = max(0.0, min(100.0, 100.0 - $blendSkip));
        }

        if ($blendSkip !== null) {
            $blendSkip = round($blendSkip, 1);
        }

        if ($blendAi !== null) {
            $blendAi = round($blendAi, 1);
        }

        $blendValue = '';
        if ($blendSkip !== null && $blendAi !== null) {
            $blendValue = $blendSkip . '% Skip / ' . $blendAi . '% AI';
        } elseif ($blendSkip !== null) {
            $blendValue = $blendSkip . '% Skip';
        } elseif ($blendAi !== null) {
            $blendValue = $blendAi . '% AI';
        }

        if ($blendValue !== '') {
            echo '[[div class="setting-row"]]'
               . '[[strong]]Blend:[[/strong]] '
               . htmlspecialchars($blendValue, ENT_QUOTES)
               . '[[/div]]';
        }
    }
  }

  echo '[[/div]]';
} else {
    // [SKAI-FIX-02] For source types without predefined labels, render any available JSON settings
    $rawSettingsJson = null;
    foreach (['settings_json','settings','params_json','params','meta_json','config_json'] as $__k) {
        if (isset($s->$__k) && $s->$__k !== null && $s->$__k !== '') {
            $rawSettingsJson = (string)$s->$__k;
            break;
        }
    }
    $__genericSettings = [];
    if ($rawSettingsJson) {
        $__parsed = json_decode($rawSettingsJson, true);
        if (is_array($__parsed)) $__genericSettings = $__parsed;
    }
    if (!empty($__genericSettings)) {
        $__friendlyKeys = [
            'draw_window'    => 'History used',
            'epochs'         => 'Training rounds',
            'walks'          => 'Search steps',
            'numbers_count'  => 'Numbers generated',
            'history_length' => 'History length',
            'iterations'     => 'Iterations',
            'seed'           => 'Seed',
            'model'          => 'Model',
        ];
        echo '[[div class="settings-grid"]]';
        foreach ($__genericSettings as $__gk => $__gv) {
            if (is_scalar($__gv)) {
                $__label = $__friendlyKeys[$__gk] ?? ucwords(str_replace('_', ' ', (string)$__gk));
                echo '[[div class="setting-row"]]'
                   . '[[strong]]' . htmlspecialchars($__label, ENT_QUOTES) . ':[[/strong]] '
                   . htmlspecialchars((string)$__gv, ENT_QUOTES)
                   . '[[/div]]';
            }
        }
        echo '[[/div]]';
    } else {
        echo '[[em]]No advanced settings were saved for this run.[[/em]]';
    }
}

?>

[[/div]]

<?php
  // inside the foreach where $g (and $s) are in scope (safe against invalid dates):
  $__dts = strtotime((string)($g['draw_date'] ?? ''));
  $prettyDate = $__dts ? date('M j, Y', $__dts) : (string)($g['draw_date'] ?? '');
  $defaultName = htmlspecialchars(
    (string)$g['lottery_name'] . ' - ' . $prettyDate,
    ENT_QUOTES
  );

?>

<?php
// Ensure $settingLabels exists for the save-template form scope
if (!isset($settingLabels) || !is_array($settingLabels)) {
    $settingLabels = [];
}
?>

[[form method="post" style="margin-top:0.75rem;"]]
  <?php echo \Joomla\CMS\HTML\HTMLHelper::_('form.token'); ?>
  [[input type="hidden" name="save_setting_template" value="1"]]
  [[input type="hidden" name="source"              value="<?php echo $s->source; ?>"]]
[[input type="hidden" name="lottery_id"          value="<?php echo (int)$s->lottery_id; ?>"]]

<?php
// Carry JSON settings payload into the saved template (single source of truth)
$__rawSettingsJson = null;
foreach (['settings_json','settings','params_json','params','meta_json','config_json'] as $__k) {
    if (isset($s->$__k) && $s->$__k !== null && $s->$__k !== '') {
        $__rawSettingsJson = (string)$s->$__k;
        break;
    }
}
if ($__rawSettingsJson !== null):
?>
  [[input type="hidden" name="settings_json" value="<?php echo htmlspecialchars($__rawSettingsJson, ENT_QUOTES); ?>"]]
<?php endif; ?>

<?php foreach (array_keys($settingLabels) as $prop): ?>
   <?php if (isset($s->$prop) && $s->$prop !== '' && $s->$prop !== null): ?>
     [[input type="hidden" name="<?php echo $prop; ?>"
               value="<?php echo htmlspecialchars((string)$s->$prop, ENT_QUOTES); ?>"]]
   <?php endif; ?>
 <?php endforeach; ?>

  <!-- here we prefill the template name -->
  [[input type="text"
          name="setting_name"
          value="<?php echo $defaultName; ?>"
          placeholder="Save as template�"
          style="width:100%; padding:6px; margin-bottom:6px; font-size:.9rem;"]]

  [[button type="submit" class="btn-primary"
           style="padding:0.3rem 0.8rem; font-size:.8rem;"]]
    Save Settings
  [[/button]]
[[/form]]

[[form method="post" style="margin-top:.5rem;" onsubmit="return confirm('Delete this saved prediction?');"]]
  <?php echo \Joomla\CMS\HTML\HTMLHelper::_('form.token'); ?>

  [[input type="hidden" name="delete_set"
             value="<?php echo (int)$s->id; ?>"]]
  [[button type="submit" class="btn-danger"]]Delete[[/button]]
[[/form]]
[[/div]]  <!-- end .prediction-card -->
                
 <?php endforeach; ?>

 <?php else: ?>

  [[div class="prediction-card prediction-placeholder"]]

    [[div class="prediction-placeholder-title"
           style="font-size:1rem; font-weight:600; color:#2c3e50; text-align:center; margin-bottom:0.5rem;"]]
      No <?php echo htmlspecialchars($moduleLabel, ENT_QUOTES); ?> predictions yet
    [[/div]]

    [[div style="text-align:center; font-size:0.95rem; color:#444; line-height:1.5; margin-bottom:1rem; padding:0 0.5rem;"]]
      Run this analysis to fill this column. After you save a prediction, it will appear here with the matching draw so you can review how your picks performed.
    [[/div]]

    [[div style="text-align:center; margin-top:auto;"]]
      <?php if (!empty($lottoUrl)): ?>
[[a href="<?php echo htmlspecialchars($lottoUrl, ENT_QUOTES); ?>"
             target="_blank"
             rel="noopener"
             class="btn-primary btn-insight"]]
  Run <?php echo htmlspecialchars($moduleLabel, ENT_QUOTES); ?> Prediction
[[/a]]

      <?php else: ?>
        [[button type="button" class="btn-primary btn-insight" disabled]]
          Run <?php echo htmlspecialchars($moduleLabel, ENT_QUOTES); ?> Prediction
        [[/button]]
      <?php endif; ?>
    [[/div]]

  [[/div]]

<?php endif; ?>

          [[/div]]  <!-- end .prediction-column -->

        <?php endforeach; ?>
      [[/div]]  <!-- end .predictions-row -->

      <?php /* [SKAI-REF-04] Narrative container: JS renders Signal/Meaning/Next Move blocks here.
              $__drawRow is truthy (array) or false/null - ternary outputs only the literal strings
              'post' or 'pre', which are safe values for this data attribute. */ ?>
      [[div class="narrative-card" data-narrative
data-mode="<?php echo ($__mode === 'post') ? 'post' : 'pre'; ?>"
           style="margin:1rem 0 0.5rem; padding:0.85rem 1rem; border-radius:10px; background:#f8fafc; border:1px solid #e3e7ef;"
           role="status" aria-live="polite"
           aria-label="Analysis narrative for this draw"]]
      [[/div]]

    [[/div]]  <!-- end .lottery-collapsible-content -->
    [[/div]]  <!-- end .lottery-group -->
  <?php endforeach; ?>

<?php endif; ?>
[[/div]]  <!-- end .prediction-panel -->

<?php
// Load user's saved settings templates
$q = $db->getQuery(true)
    ->select([
        's.*',
        'l.name AS lottery_name',
        'st.name AS state_name'
    ])
    ->from($db->quoteName('#__user_saved_settings', 's'))
    ->join('LEFT', $db->quoteName('#__lotteries', 'l') . ' ON s.lottery_id = l.lottery_id')
    ->join('LEFT', $db->quoteName('#__states', 'st') . ' ON l.state_id = st.state_id')
    ->where('s.user_id = ' . (int) $user->id)
    ->order('s.created_at DESC');

$db->setQuery($q);
$savedSettings = $db->loadAssocList() ?: [];

?>
<?php if (!empty($savedSettings)): ?>
  [[div class="card" style="margin-top:2rem;"]]
    [[div class="card-header"]]
      [[h3 class="title"]]Saved Configurations[[/h3]]
      [[p style="margin:0; color:var(--skai-text-secondary); font-size:0.9rem;"]]Reuse your tested settings for future predictions[[/p]]
    [[/div]]
    [[ul style="list-style:none; margin:0; padding:1rem;"]]
      <?php foreach ($savedSettings as $set):
        // Decode JSON params
        $params = json_decode($set['params'], true) ?: [];
      ?>
        [[li style="margin-bottom:1.5rem;"]]
          [[strong]]<?php echo htmlspecialchars($set['setting_name'], ENT_QUOTES); ?>[[/strong]] -
          <?php
            // Source label + location
            $sourceLabel = ucfirst(str_replace('_', ' ', $set['source']));
            $state       = htmlspecialchars($set['state_name'] ?? 'Unknown', ENT_QUOTES);
            $lotteryName = htmlspecialchars($set['lottery_name'] ?? 'Unknown', ENT_QUOTES);
            echo "$sourceLabel for $state � $lotteryName";
          ?>
          [[br]]
          [[em]]<?php echo date('M j, Y g:ia', strtotime($set['created_at'])); ?>[[/em]]

          <?php if ($params): ?>
            [[div class="settings-params" style="font-size:0.85rem; color:#333; margin-top:0.5rem;"]]
              <?php
                // human-friendly labels
                $labels = [
                  'draws_analyzed'      => 'Draws',
                  'freq_weight'         => 'Freq�Wt',
                  'skip_weight'         => 'Skip�Wt',
                  'hist_weight'         => 'Hist�Wt',
                  'epochs'              => 'Epochs',
                  'batch_size'          => 'Batch',
                  'dropout_rate'        => 'Dropout',
                  'learning_rate'       => 'LR',
                  'activation_function' => 'ActFn',
                  'hidden_layers'       => 'Layers',
                  'walks'               => 'Walks',
                  'burn_in'             => 'Burn-in',
                  'laplace_k'           => 'Laplace�K',
                  'decay'               => 'Decay',
                  'chain_len'           => 'Chain�Len',
                ];
                $parts = [];
                foreach ($params as $key => $val) {
                  $lbl = $labels[$key] ?? ucwords(str_replace('_',' ',$key));
                  $parts[] = htmlspecialchars($lbl, ENT_QUOTES) . ': ' . htmlspecialchars($val, ENT_QUOTES);
                }
                echo implode('�|�', $parts);
              ?>
            [[/div]]
          <?php endif; ?>

[[form method="post" style="margin-top:0.5rem;" onsubmit="return confirm('Delete this saved settings template?');"]]
  <?php echo \Joomla\CMS\HTML\HTMLHelper::_('form.token'); ?>

  [[input type="hidden" name="delete_setting_template" value="<?php echo (int)$set['id']; ?>"]]
  [[button type="submit" class="btn-danger" style="font-size:0.75rem; padding:0.3rem 0.8rem;"]]
    Delete
  [[/button]]
[[/form]]

        [[/li]]
      <?php endforeach; ?>
    [[/ul]]
  [[/div]]
<?php endif; ?>


[[script]]

document.addEventListener('DOMContentLoaded', function() {
// Sorcerer decoder bootstrap (bulletproof)
var S = (typeof window.S === 'function')
  ? window.S
  : function(str){
      return String(str).replace(/\[\[/g, '<').replace(/\]\]/g, '>');
    };

// Ensure global access (some blocks call S later)
window.S = S;

try { // [SKAI-GUARD] Catch errors so they don't prevent collapse/snapshot (in separate script)



// =========================================================
// SKAI Lottery Navigator (pill row)
// =========================================================
(function buildLotteryNavigator(){
  var pillsEl = document.getElementById('skai-nav-pills-inner');
  if (!pillsEl) return;

  var groups = document.querySelectorAll('.lottery-group[data-lottery-id]');
  if (!groups || !groups.length) {
    var wrap = document.getElementById('skai-lottery-navigator');
    if (wrap) wrap.style.display = 'none';
    return;
  }

  var html = '';
  var seenLids = {};
  Array.prototype.forEach.call(groups, function(group) {
    var lid = group.getAttribute('data-lottery-id') || '';
    if (!lid || seenLids[lid]) return;
    seenLids[lid] = true;
    // Build label from first .lottery-name spans
    var nameEls = group.querySelectorAll('.lottery-name');
    var parts = [];
    Array.prototype.forEach.call(nameEls, function(el) {
      var t = (el.textContent || '').trim();
      if (t) parts.push(t);
    });
    var label = parts.slice(-2).join(' - ') || ('Lottery ' + lid);
    // Shorten label to max 28 chars for pill readability
    if (label.length > 28) label = label.slice(0, 26) + '...';
    // Safely escape HTML entities, including single quotes
    label = label.replace(/[&<>"']/g, function(c){
      return {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
      }[c];
    });
    html += '<a class="skai-nav-pill" href="#" data-target-lid="' + lid + '" tabindex="0" role="link" aria-current="false">' + label + '</a>';  });

  pillsEl.innerHTML = html;

  // Click handler: scroll to lottery group + highlight
  pillsEl.addEventListener('click', function(ev) {
    var pill = ev.target;
    if (!pill || !pill.classList.contains('skai-nav-pill')) return;
    ev.preventDefault();

    var lid = pill.getAttribute('data-target-lid') || '';
    if (!lid) return;

    var target = document.querySelector('.lottery-group[data-lottery-id="' + lid + '"]');
    if (!target) return;

    // Expand if collapsed
    if (target.classList.contains('collapsed')) {
      target.classList.remove('collapsed');
      var tog = target.querySelector('.lottery-collapse-toggle');
      if (tog) {
        tog.textContent = 'Collapse';
        tog.setAttribute('aria-expanded', 'true');
      }
    }

    // Scroll
    try {
      target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } catch(e) {
      target.scrollIntoView(true);
    }

    // Update aria-current
    Array.prototype.forEach.call(pillsEl.querySelectorAll('.skai-nav-pill'), function(p) {
      p.setAttribute('aria-current', 'false');
    });
    pill.setAttribute('aria-current', 'true');
  });

  // Keyboard handler
  pillsEl.addEventListener('keydown', function(ev) {
    if (ev.key === 'Enter' || ev.key === ' ') {
      var pill = ev.target;
      if (pill && pill.classList.contains('skai-nav-pill')) {
        ev.preventDefault();
        pill.click();
      }
    }
  });
})();

// =========================================================
// SKAI Best Settings Card + History Collapsible
// For each .prediction-column: compute best run, render
// pinned card, wrap history in collapsible if > 3 runs.
// =========================================================
(function buildBestSettingsAndHistory(){
  var HISTORY_CAP = 3;

function escHtml(str) {
    return String(str).replace(/[&<>"']/g, function(c){
      switch (c) {
        case '&':  return '&amp;';
        case '<':  return '&lt;';
        case '>':  return '&gt;';
        case '"':  return '&quot;';
        case "'":  return '&#39;';
        default:   return c;
      }
    });
  }
  function countMatchPills(card) {
    if (!card) return 0;
    return card.querySelectorAll('.match-pill:not(.extra)').length;
  }

  function getTimestamp(card) {
    if (!card) return 0;
    var tsEl = card.querySelector('[data-timestamp]');
    if (!tsEl) return 0;
    var iso = tsEl.getAttribute('data-timestamp') || '';
    var t = Date.parse(iso);
    return isFinite(t) ? t : 0;
  }

  function getSettingsSummary(card) {
    if (!card) return '';
    var rows = card.querySelectorAll('.setting-row');
    var parts = [];
    Array.prototype.forEach.call(rows, function(row) {
      var txt = (row.textContent || '').replace(/\s+/g, ' ').trim();
      if (txt) parts.push(txt);
    });
    return parts.slice(0, 5).join(' | ');
  }

  function getNumbers(card) {
    if (!card) return [];
    var pills = card.querySelectorAll('.match-pill:not(.extra), .no-match:not(.extra), .pred-pill:not(.extra)');
    var out = [];
    Array.prototype.forEach.call(pills, function(p) {
      var n = parseInt((p.textContent || '').trim(), 10);
      if (isFinite(n)) out.push(n);
    });
    return out.slice(0, 10);
  }

  function fmtAgo(ts) {
    if (!ts) return '';
    var s = Math.max(0, Math.floor((Date.now() - ts) / 1000));
    if (s < 60) return s + 's ago';
    var m = Math.floor(s / 60);
    if (m < 60) return m + 'm ago';
    var h = Math.floor(m / 60);
    if (h < 24) return h + 'h ago';
    return Math.floor(h / 24) + 'd ago';
  }

  function buildBestCard(bestCard, allCards, methodLabel) {
    var hits = countMatchPills(bestCard);
    var ts   = getTimestamp(bestCard);
    var nums = getNumbers(bestCard);
    var settings = getSettingsSummary(bestCard);
    var hasDraw = bestCard.querySelector('.drawn-pill') !== null;

    var scoreLabel = hasDraw
      ? (hits + ' hit' + (hits === 1 ? '' : 's') + ' on drawn numbers')
      : 'Most recent analysis';

    var numbersHtml = nums.map(function(n) {
      var isHit = bestCard.querySelector('.match-pill') &&
        (function(){
          var pills = bestCard.querySelectorAll('.match-pill:not(.extra)');
          for (var i=0; i<pills.length; i++) {
            if (parseInt((pills[i].textContent||'').trim(),10) === n) return true;
          }
          return false;
        })();
      var extra = isHit ? ' style="background:#dcfce7;border-color:#16a34a;color:#14532d;"' : '';
      return '<span class="skai-best-card__pill"' + extra + '>' + n + '</span>';
    }).join('');

    var settingsHtml = settings
      ? ('<div class="skai-best-card__settings"><strong>Settings:</strong> ' + escHtml(settings) + '</div>')
      : '<div class="skai-best-card__note">No advanced settings recorded for this run.</div>';

    var tsHtml = ts
      ? ('<div class="skai-best-card__ts">Saved: ' + fmtAgo(ts) + '</div>')
      : '';

    var stableNote = (function(){
      if (allCards.length < 2) return '';
      var topNums = {};
      Array.prototype.forEach.call(allCards, function(c){
        var ns = getNumbers(c);
        ns.forEach(function(n){ topNums[n] = (topNums[n]||0) + 1; });
      });
      var repeaters = Object.keys(topNums).filter(function(k){ return topNums[k] >= 2; }).length;
      if (repeaters >= 3) return '<div class="skai-best-card__note">Stability: ' + repeaters + ' numbers appear in 2+ runs -- consistent pattern detected.</div>';
      return '<div class="skai-best-card__note">Stability: low -- numbers vary across runs. Keep iterating.</div>';
    })();

    return '<div class="skai-best-card">' +
      '<div class="skai-best-card__head">' +
        '<span class="skai-best-card__label">Best Settings -- ' + escHtml(methodLabel||'') + '</span>' +
        '<span class="skai-best-card__score">' + scoreLabel + '</span>' +
      '</div>' +
      '<div class="skai-best-card__numbers">' + (numbersHtml || '<em style="font-size:0.8rem;color:#7F8DAA;">No numbers found</em>') + '</div>' +
      settingsHtml +
      tsHtml +
      stableNote +
    '</div>';
  }

  var columns = document.querySelectorAll('.prediction-column');
  Array.prototype.forEach.call(columns, function(col) {
    var cards = col.querySelectorAll('.prediction-card:not(.common-card):not(.prediction-placeholder)');
    if (!cards || !cards.length) return;

    // Determine method label from first card's source badge
    var methodLabel = '';
    var firstBadge = cards[0].querySelector('.source-badge');
    if (firstBadge) methodLabel = (firstBadge.textContent || '').trim().replace(/\s+/g,' ');

    // Find best card (most main hits, then extra hits, then most recent)
    var bestCard  = null;
    var bestHits  = -1;
    var bestExtra = -1;
    var bestTs    = -1;
    Array.prototype.forEach.call(cards, function(card) {
      var h = countMatchPills(card);
      var e = card.querySelectorAll('.match-pill.extra').length;
      var t = getTimestamp(card);
      if (h > bestHits
          || (h === bestHits && e > bestExtra)
          || (h === bestHits && e === bestExtra && t > bestTs)) {
        bestHits  = h;
        bestExtra = e;
        bestTs    = t;
        bestCard  = card;
      }
    });

    if (!bestCard) return;

    // Insert best settings card at top of column
    var bestHtml = buildBestCard(bestCard, Array.prototype.slice.call(cards), methodLabel);
    var bestEl = document.createElement('div');
    bestEl.innerHTML = S(bestHtml);
    col.insertBefore(bestEl.firstChild, col.firstChild);

    // Always wrap all original cards in a history accordion (show only best card by default)
    if (cards.length > 0) {
      var detailsEl = document.createElement('details');
      detailsEl.className = 'skai-history-details';

      var summaryEl = document.createElement('summary');
      summaryEl.className = 'skai-history-summary';
      summaryEl.innerHTML = S(
        'View History' +
        '[[span class="skai-history-count"]]' + cards.length + (cards.length === 1 ? ' run' : ' runs') + '[[/span]]'
      );
      detailsEl.appendChild(summaryEl);

      var bodyEl = document.createElement('div');
      bodyEl.className = 'skai-history-body';

      // Move all original cards into the history accordion
      Array.prototype.forEach.call(cards, function(card) {
        bodyEl.appendChild(card);
      });
      detailsEl.appendChild(bodyEl);
      col.appendChild(detailsEl);
    }
  });
})();
// - per-lottery memory (localStorage, bounded)
// - simple trends + posture + next steps
// =============================
var __SKAI_LIVE_V__ = 1;
var __SKAI_LIVE_NS__ = 'skai_live_v' + __SKAI_LIVE_V__ + '_';

function skaiSafeJsonParse(str, fallback){
  try { return JSON.parse(str); } catch(e){ return fallback; }
}
function skaiSafeJsonStringify(obj, fallback){
  try { return JSON.stringify(obj); } catch(e){ return fallback || '[]'; }
}
function skaiLiveKey(lotteryId){
  return __SKAI_LIVE_NS__ + 'lottery_' + String(lotteryId || 'unknown');
}
function skaiLiveAppend(lotteryId, entry, maxKeep){
  maxKeep = (typeof maxKeep === 'number' && maxKeep > 3) ? maxKeep : 12;

  var key = skaiLiveKey(lotteryId);
  var arr = [];
  try {
    arr = skaiSafeJsonParse(localStorage.getItem(key) || '[]', []);
    if (!Array.isArray(arr)) arr = [];
  } catch(e){
    arr = [];
  }

  arr.push(entry);
  if (arr.length > maxKeep) arr = arr.slice(arr.length - maxKeep);

  try { localStorage.setItem(key, skaiSafeJsonStringify(arr, '[]')); } catch(e){}
  return arr;
}
function skaiLiveRead(lotteryId){
  var key = skaiLiveKey(lotteryId);
  try {
    var arr = skaiSafeJsonParse(localStorage.getItem(key) || '[]', []);
    return Array.isArray(arr) ? arr : [];
  } catch(e){
    return [];
  }
}

// =============================
// SKAI Baseline helpers (per lottery)
// =============================
function skaiBaselineKey(lotteryId){
  return 'skai_baseline_' + String(lotteryId || 'unknown');
}

function skaiBaselineSave(lotteryId, entry){
  if (!lotteryId || !entry) return;
  try {
    localStorage.setItem(skaiBaselineKey(lotteryId), JSON.stringify(entry));
  } catch(e){}
}

function skaiBaselineRead(lotteryId){
  if (!lotteryId) return null;
  try {
    var raw = localStorage.getItem(skaiBaselineKey(lotteryId));
    if (!raw) return null;
    return JSON.parse(raw);
  } catch(e){
    return null;
  }
}

// =============================
// SKAI Live Snapshot helpers
// =============================
function skaiFmtAgo(iso){

  if (!iso) return '';
  var t = Date.parse(iso);
  if (!isFinite(t)) return '';
  var s = Math.max(0, Math.floor((Date.now() - t) / 1000));
  if (s < 60) return s + 's ago';
  var m = Math.floor(s / 60);
  if (m < 60) return m + 'm ago';
  var h = Math.floor(m / 60);
  if (h < 24) return h + 'h ago';
  var d = Math.floor(h / 24);
  return d + 'd ago';
}

function skaiLevelToScore(levelLabel){
  var map = { 'Single-method':0, 'Exploratory':1, 'Balanced':2, 'High':3 };
  return (map[levelLabel] != null) ? map[levelLabel] : 1;
}

function skaiDeltaText(curr, prev){
  if (!isFinite(curr) || !isFinite(prev)) return '';
  var d = curr - prev;
  if (d === 0) return 'no change';
  if (d > 0) return '+' + d;
  return String(d);
}

function skaiMiniBars(arr, field, max, bars){
  bars = bars || 6;
  max = (isFinite(max) && max > 0) ? max : 10;

  if (!arr || !arr.length) return '';
  var slice = arr.slice(Math.max(0, arr.length - bars));
  var html = '[[div class="skai-mini-bars" aria-hidden="true"]]';

  for (var i = 0; i < slice.length; i++){
    var v = Number(slice[i] && slice[i][field]);
    if (!isFinite(v) || v < 0) v = 0;
    if (v > max) v = max;
    var pct = Math.round((v / max) * 100);
    html += '[[span class="skai-mini-bar"]][[span class="skai-mini-bar-fill" style="height:' + pct + '%;"]][[/span]][[/span]]';
  }
  html += '[[/div]]';
  return html;
}

function skaiShortMode(mode){
  mode = String(mode || '').toLowerCase();
  if (mode === 'post') return 'Post';
  if (mode === 'pre') return 'Pre';
  return 'Pre';
}

function skaiSafeText(s){
  return String(s == null ? '' : s).replace(/[<>]/g, '');
}

// Build a compact "settings signature" from the currently selected cards.
// Purpose: detect what changed between runs (window/epochs/walks/smoothing/etc.)
function skaiSettingsSigFromChecked(checked){
  if (!checked || !checked.length) return '';

  var parts = [];
  for (var i = 0; i < checked.length; i++){
    var cb = checked[i];
    var card = cb && cb.closest ? cb.closest('.prediction-card') : null;
    if (!card) continue;

    var method = String(card.getAttribute('data-source') || '').trim();

    // Try structured settings rows first (your CSS implies .settings-grid/.setting-row exists)
    var rows = card.querySelectorAll('.settings-grid .setting-row');
    var rowParts = [];

    if (rows && rows.length) {
      for (var r = 0; r < rows.length; r++){
        var txt = rows[r].textContent || '';
        txt = txt.replace(/\s+/g,' ').trim();
        if (txt) rowParts.push(txt);
      }
    } else {
      // Fallback: any visible settings panel text
      var panel = card.querySelector('.settings-panel');
      var t = panel ? (panel.textContent || '') : '';
      t = t.replace(/\s+/g,' ').trim();
      if (t) rowParts.push(t);
    }

    // Normalize and bound size
    var sig = rowParts.join('|');
    sig = sig.replace(/\s+/g,' ').trim();
    if (sig.length > 220) sig = sig.slice(0, 220);

    parts.push(method + ':' + sig);
  }

  // Stable ordering helps comparisons even if DOM order shifts
  parts.sort();
  return parts.join(' || ');
}

// A human-readable "what changed" note (best-effort, ninth-grade)
function skaiSettingsChangeNote(prevSig, nowSig){
  if (!prevSig || !nowSig) return '';

  if (prevSig === nowSig) return '';

  // If a single method is selected, highlight it
  var p = prevSig.split(' || ');
  var n = nowSig.split(' || ');

  // Find first difference segment
  var max = Math.max(p.length, n.length);
  for (var i = 0; i < max; i++){
    if ((p[i] || '') !== (n[i] || '')) {
      var seg = (n[i] || p[i] || '');
      var method = seg.split(':')[0] || 'a method';
      return 'Settings changed for ' + method + '. Try one small change at a time so you can see what improves Agreement.';
    }
  }

  return 'Settings changed. Try one small change at a time so you can see what improves Agreement.';
}

function skaiRunRowHTML(r){
  var ts = r && r.ts ? skaiFmtAgo(r.ts) : '';
  var mode = skaiShortMode(r && r.mode);
  var mu = isFinite(Number(r && r.methodsUsed)) ? Number(r.methodsUsed) : 0;
  var a  = isFinite(Number(r && r.overlapScore)) ? Number(r.overlapScore) : 0;
  var e  = isFinite(Number(r && r.evidenceScore)) ? Number(r.evidenceScore) : 0;
  var lvl = skaiSafeText(r && r.levelLabel ? r.levelLabel : '');

  var html = '';
  html += '[[div class="skai-run-row"]]';

  html += '[[div class="skai-run-when"]]' + (ts || '') + '[[/div]]';

  html += '[[div class="skai-run-meta"]]';
  html += '[[span class="skai-chip skai-chip-mode"]]' + mode + '[[/span]]';
  html += '[[span class="skai-chip"]]Methods: ' + mu + '[[/span]]';
  if (lvl) html += '[[span class="skai-chip skai-chip-level"]]' + lvl + '[[/span]]';
  html += '[[/div]]';

  html += '[[div class="skai-run-metrics"]]';
  html += '[[div class="skai-run-metric"]]';
  html += '[[span class="dot dot-agreement"]][[/span]]';
  html += '[[span class="k"]]Agreement[[/span]]';
  html += '[[span class="v"]]' + a + '[[/span]]';
  html += '[[/div]]';

  html += '[[div class="skai-run-metric"]]';
  html += '[[span class="dot dot-evidence"]][[/span]]';
  html += '[[span class="k"]]Evidence[[/span]]';
  html += '[[span class="v"]]' + e + '[[/span]]';
  html += '[[/div]]';
  html += '[[/div]]';

  html += '[[/div]]';
  return html;
}

function skaiRunTimelineHTML(historyArr, maxRows){
  if (!historyArr || !historyArr.length) return '';
  maxRows = (maxRows|0) || 10;

  var rows = historyArr.slice(Math.max(0, historyArr.length - maxRows));
  var html = '';

  html += '[[details class="skai-run-timeline"]]';
  html += '[[summary class="skai-run-summary"]]';
  html += '[[span class="skai-run-sum-title"]][[strong]]Run timeline[[/strong]][[/span]]';
  html += '[[span class="skai-run-sum-sub"]]Last ' + rows.length + ' runs - see what changed[[/span]]';
  html += '[[/summary]]';

  html += '[[div class="skai-run-body"]]';
  for (var i = 0; i < rows.length; i++){
    html += skaiRunRowHTML(rows[i]);
  }
  html += '[[/div]]';

  html += '[[/details]]';

  return html;
}


function skaiLiveSnapshotHTML(historyArr){
  if (!historyArr || !historyArr.length) return '';

  var last = historyArr[historyArr.length - 1];
  var prev = (historyArr.length >= 2) ? historyArr[historyArr.length - 2] : null;

  var a  = Number(last && last.overlapScore);
  var e  = Number(last && last.evidenceScore);
  var cs = skaiLevelToScore(last && last.levelLabel);

  var ap = prev ? Number(prev.overlapScore) : NaN;
  var ep = prev ? Number(prev.evidenceScore) : NaN;
  var cp = prev ? skaiLevelToScore(prev.levelLabel) : NaN;

  var aDelta = prev ? skaiDeltaText(a, ap) : '';
  var eDelta = prev ? skaiDeltaText(e, ep) : '';
  var cDelta = prev ? skaiDeltaText(cs, cp) : '';

  var ago = skaiFmtAgo(last && last.ts);

  var html = '';
  html += '[[div class="skai-live-snapshot"]]';
  html += '[[div class="skai-live-head"]]';
  html += '[[div class="skai-live-title"]][[strong]]Live snapshot[[/strong]][[/div]]';
  html += '[[div class="skai-live-sub"]]' + (ago ? ('Last run: ' + ago) : 'Last run saved') + '[[/div]]';
  html += '[[/div]]';

  html += '[[div class="skai-live-grid"]]';

  // Agreement
  html += '[[div class="skai-metric"]]';
  html += '[[div class="skai-metric-k"]][[span class="dot dot-agreement"]][[/span]]Agreement[[/div]]';
  html += '[[div class="skai-metric-v"]]' + (isFinite(a) ? a : 0) + '[[/div]]';
  html += '[[div class="skai-metric-d"]]' + (prev ? ('Since last: ' + aDelta) : 'Save one more run to compare') + '[[/div]]';
  html += skaiMiniBars(historyArr, 'overlapScore', 10, 6);
  html += '[[/div]]';

  // Evidence
  html += '[[div class="skai-metric"]]';
  html += '[[div class="skai-metric-k"]][[span class="dot dot-evidence"]][[/span]]Evidence[[/div]]';
  html += '[[div class="skai-metric-v"]]' + (isFinite(e) ? e : 0) + '[[/div]]';
  html += '[[div class="skai-metric-d"]]' + (prev ? ('Since last: ' + eDelta) : 'Save one more run to compare') + '[[/div]]';
  html += skaiMiniBars(historyArr, 'evidenceScore', 10, 6);
  html += '[[/div]]';

  // Confidence
  html += '[[div class="skai-metric"]]';
  html += '[[div class="skai-metric-k"]][[span class="dot dot-confidence"]][[/span]]Confidence[[/div]]';
  html += '[[div class="skai-metric-v"]]' + (last && last.levelLabel ? String(last.levelLabel) : 'Exploratory') + '[[/div]]';
  html += '[[div class="skai-metric-d"]]' + (prev ? ('Since last: ' + cDelta) : 'Save one more run to compare') + '[[/div]]';
  html += '[[div class="skai-confidence-meter" aria-hidden="true"]]';
  html += '[[span class="skai-confidence-fill" style="width:' + Math.round((cs / 3) * 100) + '%;"]][[/span]]';
  html += '[[/div]]';
  html += '[[/div]]';

  html += '[[/div]]'; // grid
html += '<div class="skai-live-cta">Tip: change <strong>one setting</strong> and save a new run - that\'s how the page learns what improves Agreement.</div>';
  html += '[[/div]]';

  return html;
}




function skaiTrendSentence(arr, field, label){
  label = label || 'Signal';
  if (!arr || arr.length < 3) return '';

  var last3 = arr.slice(-3);
  var a = Number(last3[0] && last3[0][field]);
  var b = Number(last3[1] && last3[1][field]);
  var c = Number(last3[2] && last3[2][field]);
  if (!isFinite(a) || !isFinite(b) || !isFinite(c)) return '';

  var delta = c - a;
  if (delta >= 2) return label + ' is rising over the last 3 runs.';
  if (delta <= -2) return label + ' is weakening over the last 3 runs.';

  var vol = Math.abs(b - a) + Math.abs(c - b);
  if (vol >= 4) return label + ' has been volatile recently.';
  return label + ' has been relatively steady.';
}
function skaiConfidenceTrend(arr){
  if (!arr || arr.length < 3) return '';
  var map = { 'Single-method':0, 'Exploratory':1, 'Balanced':2, 'High':3 };
  var last3 = arr.slice(-3).map(function(x){
    var k = (x && x.levelLabel) ? x.levelLabel : 'Exploratory';
    return map[k] || 0;
  });
  var a = last3[0], b = last3[1], c = last3[2];
  var delta = c - a;

  if (delta >= 2) return 'Confidence posture is improving across recent runs.';
  if (delta <= -2) return 'Confidence posture has softened across recent runs.';
  if (Math.abs((b - a)) + Math.abs((c - b)) >= 3) return 'Confidence posture has been inconsistent recently.';
  return 'Confidence posture has been stable recently.';
}
function skaiPosture(levelLabel, methodsUsed, overlapScore, evidenceScore){
  methodsUsed = Number(methodsUsed) || 0;
  overlapScore = Number(overlapScore) || 0;
  evidenceScore = Number(evidenceScore) || 0;

  if (methodsUsed <= 1 || levelLabel === 'Single-method') {
    return 'Signal quality is limited because only one method is active - useful for exploration, but not for agreement.';
  }
  if (levelLabel === 'High') {
    return 'Signal quality looks consistent - multiple methods are pointing in similar directions.';
  }
  if (levelLabel === 'Balanced') {
    return 'Signal quality is workable - some agreement exists, with normal uncertainty.';
  }
  if (overlapScore === 0 && evidenceScore === 0) {
    return 'Signal quality is early-stage - treat this run as learning-focused and keep building history.';
  }
  return 'Signal quality is exploratory - good for learning patterns, not for precision.';
}
function skaiNextSteps(levelLabel, checkedLen, methodsUsed, overlapScore, evidenceScore){
  var steps = [];

  checkedLen    = Number(checkedLen) || 0;
  methodsUsed   = Number(methodsUsed) || 0;
  overlapScore  = Number(overlapScore) || 0;
  evidenceScore = Number(evidenceScore) || 0;

  if (checkedLen === 1) {
    steps.push('Add one more method so you can measure agreement (not just one viewpoint).');
steps.push('Try "Compare All" once - it helps you see where methods overlap.');

    return steps;
  }

  // Multi-method but no overlap: likely different assumptions/settings
  if (methodsUsed >= 2 && overlapScore === 0) {
    steps.push('Your selected methods disagree right now. That\'s useful - it means you\'re seeing different viewpoints.');
    steps.push('Try adjusting one setting at a time (draw window, epochs/walks, or smoothing), then save a new run.');
    steps.push('Aim for at least 1-2 overlaps before treating anything as a "core" set.');
    return steps;
  }

  // Some overlap: suggest strengthening evidence
  if (methodsUsed >= 2 && overlapScore > 0 && evidenceScore === 0) {
    steps.push('You have some agreement. Next: build evidence by saving a few more runs so trends can appear.');
    steps.push('Mix in a few mid-rank numbers (#4-#10) from each method so you\'re not all-in on #1 picks.');
    return steps;
  }

  // Higher quality: suggest stability and iteration discipline
  if (levelLabel === 'High' || levelLabel === 'Balanced') {
    steps.push('This is a reasonable baseline. Keep your core stable and iterate slowly (one setting change per run).');
    steps.push('After the next draw, use "post" mode to see which ranks actually hit and tune toward those ranges.');
    return steps;
  }

  return steps;
}

// Set baseline (delegated)
document.addEventListener('click', function(ev){
  var el = ev.target;
  if (!el) return;

  var link = el.closest ? el.closest('[data-skai-set-baseline]') : null;
  if (!link) return;

  ev.preventDefault();

  var lotteryId = link.getAttribute('data-lottery-id') || '';
  if (!lotteryId) return;

  try {
    var hist = skaiLiveRead(lotteryId);
    if (!hist || !hist.length) return;

    var last = hist[hist.length - 1];
    skaiBaselineSave(lotteryId, last);
    alert('Baseline set. Future runs will compare against this.');
  } catch(e){}
});

// Reset learning (delegated) - clears localStorage keys for this lottery
document.addEventListener('click', function(ev){
  var el = ev.target;
  if (!el) return;

  // Allow clicks on nested nodes (e.g., strong/span) inside the link
  var link = el.closest ? el.closest('[data-skai-reset-learning]') : null;
  if (!link) return;

  ev.preventDefault();

  var lotteryId = link.getAttribute('data-lottery-id') || '';
  if (!lotteryId) return;

  var ok = confirm('Reset learning history for this lottery? This clears trend memory used by the living analysis.');
  if (!ok) return;

  try {
    // Remove known learning namespaces (safe no-ops if they do not exist)
    var prefixes = ['skai_live_', 'skai_learning_', 'skai_learn_', 'skai_mem_'];
    for (var i = localStorage.length - 1; i >= 0; i--) {
      var k = localStorage.key(i);
      if (!k) continue;

      // Only keys that contain this lotteryId and match one of our prefixes
      var hasPrefix = false;
      for (var p = 0; p < prefixes.length; p++) {
        if (k.indexOf(prefixes[p]) === 0) { hasPrefix = true; break; }
      }
      if (!hasPrefix) continue;

      if (k.indexOf(lotteryId) !== -1) {
        localStorage.removeItem(k);
      }
    }
  } catch(e){}

  // Refresh this group: dispatch events on first checkbox
  try {
    var group = document.querySelector('.lottery-group[data-lottery-id="' + lotteryId + '"]');
    if (group) {
      var first = group.querySelector('.card-compare-checkbox');
      if (first) {
        fireFormEvents(first);
      }
    }
  } catch(e){}
});

Array.prototype.forEach.call(document.querySelectorAll('.lottery-group'), function(group) {

  function matchesSelector(el, selector) {
    if (!el || el.nodeType !== 1) return false;
    var p = Element.prototype;
    var f = p.matches || p.webkitMatchesSelector || p.mozMatchesSelector || p.msMatchesSelector;
    if (f) return f.call(el, selector);
    var nodes = (el.parentNode || document).querySelectorAll(selector);
    for (var i = 0; i < nodes.length; i++) {
      if (nodes[i] === el) return true;
    }
    return false;
  }

  function closestSelector(el, selector) {
    while (el && el !== document) {
      if (matchesSelector(el, selector)) return el;
      el = el.parentNode;
    }
    return null;
  }

  function toInt(text) {
    var s = String(text || '').replace(/[^\d]/g, '');
    if (s === '') return null;
    var n = parseInt(s, 10);
    return (isFinite(n) ? n : null);
  }

  function uniqueOrdered(arr) {
    var out = [];
    var seen = Object.create(null);
    for (var i = 0; i < arr.length; i++) {
      var k = String(arr[i]);
      if (!seen[k]) { seen[k] = true; out.push(arr[i]); }
    }
    return out;
  }

  function getCardNumbers(card, isExtra) {
    var sel = isExtra
      ? '.match-pill.extra, .no-match.extra, .pred-pill.extra'
      : '.match-pill:not(.extra), .no-match:not(.extra), .pred-pill:not(.extra)';
    var nodes = card ? card.querySelectorAll(sel) : [];
    var nums = [];
    for (var i = 0; i < nodes.length; i++) {
      var v = toInt(nodes[i].textContent);
      if (v !== null) nums.push(v);
    }
    return uniqueOrdered(nums);
  }

  function intersection(lists) {
    if (!lists || !lists.length) return [];
    var base = lists[0].slice();
    for (var i = 1; i < lists.length; i++) {
      var b = lists[i];
      var next = [];
      for (var j = 0; j < base.length; j++) {
        if (b.indexOf(base[j]) !== -1) next.push(base[j]);
      }
      base = next;
    }
    return uniqueOrdered(base);
  }

  function clearHighlights(cards) {
    for (var i = 0; i < cards.length; i++) {
      var pillNodes = cards[i].querySelectorAll('.match-pill, .no-match, .pred-pill');
      for (var j = 0; j < pillNodes.length; j++) {
        pillNodes[j].classList.remove('common-highlight');
      }
    }
  }

  function applyHighlights(card, numsMain, numsExtra) {
    if (!card) return;

    var mainNodes = card.querySelectorAll('.match-pill:not(.extra), .no-match:not(.extra), .pred-pill:not(.extra)');
    for (var i = 0; i < mainNodes.length; i++) {
      var v = toInt(mainNodes[i].textContent);
      if (v !== null && numsMain.indexOf(v) !== -1) {
        mainNodes[i].classList.add('common-highlight');
      }
    }

    var extraNodes = card.querySelectorAll('.match-pill.extra, .no-match.extra, .pred-pill.extra');
    for (var k = 0; k < extraNodes.length; k++) {
      var ve = toInt(extraNodes[k].textContent);
      if (ve !== null && numsExtra.indexOf(ve) !== -1) {
        extraNodes[k].classList.add('common-highlight');
      }
    }
  }

  function updateCommonBar() {
    var compareBoxes = group.querySelectorAll('.card-compare-checkbox');
    var commonCard = group.querySelector('.prediction-card.common-card[data-common-bar]');
    var numbersDiv = commonCard ? commonCard.querySelector('.common-numbers') : null;
    var countSpan = commonCard ? commonCard.querySelector('.common-count') : null;
    var titleEl = commonCard ? commonCard.querySelector('[data-common-title]') : null;
    var descEl  = commonCard ? commonCard.querySelector('[data-common-desc]') : null;

    var cards = group.querySelectorAll('.prediction-card');
    var checkedCards = [];
    var i;

    for (i = 0; i < compareBoxes.length; i++) {
      if (compareBoxes[i].checked) {
        var c = closestSelector(compareBoxes[i], '.prediction-card');
        if (c) checkedCards.push(c);
      }
    }

    if (!commonCard || !numbersDiv || checkedCards.length < 1) {
      if (commonCard) commonCard.style.display = 'none';
      clearHighlights(cards);
      return;
    }

    var listsMain = [];
    var listsExtra = [];
    for (i = 0; i < checkedCards.length; i++) {
      listsMain.push(getCardNumbers(checkedCards[i], false));
      listsExtra.push(getCardNumbers(checkedCards[i], true));
    }

    var commonMain = [];
    var commonExtra = [];

    if (checkedCards.length === 1) {
      commonMain = listsMain[0].slice();
      commonExtra = listsExtra[0].slice();
      if (titleEl) titleEl.textContent = 'Selected method numbers';
      if (descEl) descEl.textContent = 'Numbers from the single selected card. Select 2+ cards to see the intersection.';
    } else {
      commonMain = intersection(listsMain);
      commonExtra = intersection(listsExtra);
      if (titleEl) titleEl.textContent = 'Numbers in Common';
      if (descEl) descEl.textContent = 'Intersection across the selected cards.';
    }

    var html = '';
    if (commonMain.length) {
      for (i = 0; i < commonMain.length; i++) {
        html += '<span class="common-highlight">' + commonMain[i] + '</span> ';
      }
    } else {
      html += '<em>No main numbers in common.</em> ';
    }

    if (commonExtra.length) {
      html += '<span style="margin-left:8px;"><strong>Extra:</strong></span> ';
      for (i = 0; i < commonExtra.length; i++) {
        html += '<span class="common-highlight">' + commonExtra[i] + '</span> ';
      }
    }

    numbersDiv.innerHTML = S(html);

    if (countSpan) {
      var total = commonMain.length + commonExtra.length;
      countSpan.textContent = '(Mains: ' + commonMain.length + ', Extra: ' + commonExtra.length + ', Total: ' + total + ')';
    }

    clearHighlights(cards);
    for (i = 0; i < checkedCards.length; i++) {
      applyHighlights(checkedCards[i], commonMain, commonExtra);
    }

    commonCard.style.display = 'block';
  }

  if (!group.__skaiCompareBound) {
    group.__skaiCompareBound = true;

    group.addEventListener('change', function(ev) {
      var t = ev.target || null;
      if (!t) return;
      if (t.classList && t.classList.contains('card-compare-checkbox')) {
        updateCommonBar();
      }
    });
  }

  updateCommonBar();
}); // end forEach(lottery-group)

// Used by per-group comparison checkboxes (autoSelectOnePerMethod / update)
function fireFormEvents(el) {
  // Fire both 'input' and 'change' with bubbling to ensure listeners run
  ['input', 'change'].forEach(function(type) {
    el.dispatchEvent(new Event(type, { bubbles: true, cancelable: true }));
  });
}

// Convert all timestamps to user's local timezone
// This runs after the DOM is loaded to convert ISO timestamps to local time
function convertTimestampsToLocal() {
  Array.prototype.forEach.call(document.querySelectorAll('.local-time'), function(el) {
    var container = el.closest ? el.closest('[data-timestamp]') : null;
    if (!container) return;
    
    var isoTime = container.getAttribute('data-timestamp');
    if (!isoTime) return;
    
    try {
      var date = new Date(isoTime);
      if (isNaN(date.getTime())) return;
      
      // Format: "Dec 30, 2025, 3:45pm"
      var options = {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true
      };
      
      var localTime = date.toLocaleString('en-US', options);
      el.textContent = localTime;
    } catch (e) {
      // If conversion fails, leave the original timestamp
      console.error('Failed to convert timestamp:', isoTime, e);
    }
  });
}

// Run timestamp conversion after DOM is ready
convertTimestampsToLocal();

} catch(e) { /* [SKAI-GUARD] Main script error caught - collapse and snapshot run in separate script */ if (window.console && console.error) { console.error('[SKAI] Main script error:', e); } }

// close the top-level DOMContentLoaded wrapper opened at the start of this script
});

[[/script]]

<!-- [SKAI-ISOLATED] Collapse toggle + Global Snapshot in their own script, immune to main script errors -->
[[script]]
document.addEventListener('DOMContentLoaded', function() {

// =========================================================
// Lottery section collapse/expand functionality
// Persists state in localStorage per lottery
// =========================================================
document.addEventListener('click', function(ev) {
  var btn = ev.target ? ev.target.closest('.lottery-collapse-toggle') : null;
  if (!btn) return;

  var group = btn.closest ? btn.closest('.lottery-group') : null;
  if (!group) return;

  var lotteryId = group.getAttribute('data-lottery-id') || '';
  var storageKey = 'lottery_collapsed_' + lotteryId;
  var isCollapsed = group.classList.contains('collapsed');

  if (isCollapsed) {
    group.classList.remove('collapsed');
    btn.textContent = 'Collapse';
    btn.setAttribute('aria-expanded', 'true');
    btn.setAttribute('aria-label', 'Collapse lottery section');
    try { localStorage.setItem(storageKey, 'false'); } catch(e) {}
  } else {
    group.classList.add('collapsed');
    btn.textContent = 'Expand';
    btn.setAttribute('aria-expanded', 'false');
    btn.setAttribute('aria-label', 'Expand lottery section');
    try { localStorage.setItem(storageKey, 'true'); } catch(e) {}
  }
});

// Restore collapsed state from localStorage on page load
// Default: collapsed. Expand only if user previously expanded (stored as 'false').
Array.prototype.forEach.call(document.querySelectorAll('.lottery-group'), function(group) {
  var lotteryId = group.getAttribute('data-lottery-id') || '';
  if (!lotteryId) return;
  var toggle = group.querySelector('.lottery-collapse-toggle');
  try {
    var stored = localStorage.getItem('lottery_collapsed_' + lotteryId);
    if (stored === 'false') {
      group.classList.remove('collapsed');
      if (toggle) {
        toggle.textContent = 'Collapse';
        toggle.setAttribute('aria-expanded', 'true');
        toggle.setAttribute('aria-label', 'Collapse lottery section');
      }
    }
  } catch(e) {}
});

// =========================================================
// [SKAI-SETTINGS] Settings panel toggle -- placed in isolated script
// so it always registers regardless of errors in the main block.
// =========================================================
document.addEventListener('click', function(ev){
  var t = ev && ev.target ? ev.target : null;
  if (!t) return;
  var toggle = (t.closest ? t.closest('.settings-toggle') : null);
  if (!toggle) {
    var n = t;
    while (n && n !== document) {
      if (n.classList && n.classList.contains('settings-toggle')) { toggle = n; break; }
      n = n.parentNode;
    }
  }
  if (!toggle) return;

  var card = toggle.closest ? toggle.closest('.prediction-card') : null;
  if (!card) return;

  var panel = card.querySelector('.settings-panel');
  if (!panel) return;

  var isOpen = panel.classList.contains('open');
  isOpen = !isOpen;

  if (isOpen) {
    panel.classList.add('open');
    panel.style.display = 'block';
  } else {
    panel.classList.remove('open');
    panel.style.display = 'none';
  }

  panel.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
  toggle.textContent = isOpen ? 'Hide details' : 'Details';
  toggle.setAttribute('title', isOpen ? 'Hide details' : 'Details');
  toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');

  try { localStorage.setItem('settingsOpen_' + (card.getAttribute('data-set-id') || ''), String(isOpen)); } catch(e){}
});

// Initialise panels: collapsed by default on every page load
Array.prototype.forEach.call(document.querySelectorAll('.prediction-card'), function(card){
  var panel  = card.querySelector('.settings-panel');
  var toggle = card.querySelector('.settings-toggle');
  if (!panel || !toggle) return;
  panel.classList.remove('open');
  panel.style.display = 'none';
  panel.setAttribute('aria-hidden', 'true');
  toggle.textContent = 'Details';
  toggle.setAttribute('title', 'Details');
  toggle.setAttribute('aria-expanded', 'false');
});

// =========================================================
// [SKAI-REF-01] Best Settings Snapshot computation
// Reads data-best-avg-* attributes from each .lottery-group to build per-lottery rows.
// Aggregates unique lotteries by data-lottery-id; uses most recent draw-date group.
// =========================================================

// [SKAI-SAFETY] Ensure S exists even if another script block fails earlier
window.S = (window.S && typeof window.S === 'function')
  ? window.S
  : function(str){
      return String(str).replace(/\[\[/g, '<').replace(/\]\]/g, '>');
    };

(function buildGlobalSnapshot(){
  var cntEl          = document.getElementById('snap-lotteries-val');
  var scoredCntEl    = document.getElementById('snap-scored-lotteries-val');
  var totalScoredEl  = document.getElementById('snap-total-scored-val');
  var listEl         = document.getElementById('skai-best-settings-list');

  var groups = document.querySelectorAll('.lottery-group[data-lottery-id]');

  // Helper: safely escape text for textContent (no innerHTML risk)
  function esc(str) { return String(str || ''); }

  // Helper: expand a lottery group if collapsed
  function expandGroup(group) {
    if (!group.classList.contains('collapsed')) return;
    group.classList.remove('collapsed');
    var tog = group.querySelector('.lottery-collapse-toggle');
    if (tog) {
      tog.textContent = 'Collapse';
      tog.setAttribute('aria-expanded', 'true');
      tog.setAttribute('aria-label', 'Collapse lottery section');
      try { localStorage.setItem('lottery_collapsed_' + group.getAttribute('data-lottery-id'), 'false'); } catch(e) {}
    }
  }

  // Aggregate groups by unique lottery ID (data-lottery-id).
  // For lotteries with multiple draw-date groups, keep the most recent draw-date.
  var lotteryMap = {};
  Array.prototype.forEach.call(groups, function(group) {
    var lid      = group.getAttribute('data-lottery-id') || '';
    if (!lid) return;
    var drawDate = group.getAttribute('data-draw-date') || '';
    var existing = lotteryMap[lid];
    // Keep entry with the most recent draw-date
    if (!existing || drawDate > existing.drawDate) {
      var lotName = group.getAttribute('data-lottery-name') || '';
      if (!lotName) {
        var nameEls = group.querySelectorAll('.lottery-name');
        var parts = [];
        Array.prototype.forEach.call(nameEls, function(el) {
          var t = (el.textContent || '').trim();
          if (t) parts.push(t);
        });
        lotName = parts.join(' \u2022 ') || ('Lottery ' + lid);
      }
      lotteryMap[lid] = {
        lid:        lid,
        lotName:    lotName,
        drawDate:   drawDate,
        runId:      parseInt(group.getAttribute('data-best-avg-run-id')   || '0', 10),
        avgHits:    parseFloat(group.getAttribute('data-best-avg-hits')    || '0'),
        scored:     parseInt(group.getAttribute('data-best-avg-scored')    || '0', 10),
        lastDate:   group.getAttribute('data-best-avg-lastdate') || '',
        methodName: group.getAttribute('data-best-avg-name')     || '',
        bestHits:   parseInt(group.getAttribute('data-best-single-hits')   || '0', 10),
        sparkline:  (function() {
          try { return JSON.parse(group.getAttribute('data-sparkline') || '[]'); }
          catch (e) { return []; }
        }()),
        group:      group
      };
    }
  });

  // Build ordered array of unique lotteries
  var rows = [];
  for (var key in lotteryMap) {
    if (Object.prototype.hasOwnProperty.call(lotteryMap, key)) {
      rows.push(lotteryMap[key]);
    }
  }

  // Count totals
  var totalLotteries  = rows.length;
  var scoredLotteries = 0;
  var totalScoredRuns = 0;
  Array.prototype.forEach.call(rows, function(r) {
    if (r.scored > 0) {
      scoredLotteries++;
      totalScoredRuns += r.scored;
    }
  });

  if (cntEl)       cntEl.textContent      = String(totalLotteries);
  if (scoredCntEl) scoredCntEl.textContent = String(scoredLotteries);
  if (totalScoredEl) totalScoredEl.textContent = String(totalScoredRuns);

  if (!listEl) return;

  // Mini sparkline: simple SVG bar chart (no external libraries).
  // Requires at least 2 data points to be meaningful; single-draw lotteries show no chart.
  function renderSparkline(data) {
    if (!data || data.length < 2) return '';
    var max = 0;
    for (var i = 0; i < data.length; i++) { if (data[i] > max) max = data[i]; }
    if (max === 0) return '';
    var barW = 6; var gap = 2; var h = 22;
    var w = data.length * (barW + gap);
    var svg = '<svg width="' + w + '" height="' + h + '" style="display:inline-block;vertical-align:middle;" aria-hidden="true">';
    for (var j = 0; j < data.length; j++) {
      var bh = Math.max(2, Math.round((data[j] / max) * h));
      var x = j * (barW + gap);
      var y = h - bh;
      svg += '<rect x="' + x + '" y="' + y + '" width="' + barW + '" height="' + bh + '" fill="#20C997" rx="1"/>';
    }
    svg += '</svg>';
    return svg;
  }

  // Render each lottery as a card (only lotteries with scored data)
  var html = '';
  Array.prototype.forEach.call(rows, function(r) {
    if (r.scored <= 0) return; // skip lotteries with no scored runs
    var metricsHtml =
      '<div class="skai-bss-card__metric">' +
        '<span class="skai-bss-card__val">' + r.avgHits.toFixed(2) + '</span>' +
        '<span class="skai-bss-card__lbl">Avg Hits</span>' +
      '</div>' +
      '<div class="skai-bss-card__metric">' +
        '<span class="skai-bss-card__val">' + r.scored + '</span>' +
        '<span class="skai-bss-card__lbl">Scored Runs</span>' +
      '</div>' +
      (r.bestHits > 0
        ? '<div class="skai-bss-card__metric">' +
            '<span class="skai-bss-card__val">' + r.bestHits + '</span>' +
            '<span class="skai-bss-card__lbl">Best Run</span>' +
          '</div>'
        : '');
    var sparkHtml = (r.sparkline.length >= 2) ? '<div class="skai-bss-card__spark">' + renderSparkline(r.sparkline) + '</div>' : '';
    var actionsHtml =
      (r.runId > 0
        ? '<button type="button" class="skai-bss-view-btn" data-jump-run-id="' + r.runId + '" data-jump-lottery-id="' + esc(r.lid) + '">View best</button>'
        : '') +
      '<button type="button" class="skai-bss-all-link" data-jump-crr-id="' + esc(r.lid) + '">All runs</button>';
    html +=
      '<div class="skai-bss-card">' +
        '<div class="skai-bss-card__name">' + esc(r.lotName) + '</div>' +
        (r.methodName ? '<div class="skai-bss-card__method">' + esc(r.methodName) + '</div>' : '') +
        '<div class="skai-bss-card__metrics">' + metricsHtml + '</div>' +
        (r.lastDate ? '<div class="skai-bss-card__date">Last scored: ' + esc(r.lastDate) + '</div>' : '') +
        sparkHtml +
        '<div class="skai-bss-card__actions">' + actionsHtml + '</div>' +
      '</div>';
  });
listEl.innerHTML = (html || '');

  // CTA click handler: jump to card or CRR section
  listEl.addEventListener('click', function(ev) {
    var btn = ev.target;
    if (!btn || btn.tagName !== 'BUTTON') return;

    var isView  = btn.classList.contains('skai-bss-view-btn');
    var isSeeAll = btn.classList.contains('skai-bss-all-link');

    if (isView) {
      var runId = btn.getAttribute('data-jump-run-id') || '';
      var lid   = btn.getAttribute('data-jump-lottery-id') || '';
      if (runId) {
        var card = document.getElementById('pred-card-' + runId);
        if (card) {
          // Expand the parent lottery group if needed
          var grp = card.closest ? card.closest('.lottery-group') : null;
          if (grp) expandGroup(grp);
          try { card.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch(e) { card.scrollIntoView(true); }
          // Auto-open Details panel
          setTimeout(function() {
            var detailsBtn = card.querySelector('.settings-toggle');
            if (detailsBtn) {
              var panel = card.querySelector('.settings-panel');
              var isOpen = panel && panel.style.display !== 'none' && panel.style.display !== '';
              if (!isOpen) detailsBtn.click();
            }
          }, 350);
          return;
        }
      }
      // Fallback: scroll to lottery group
      if (lid) {
        var target = document.querySelector('.lottery-group[data-lottery-id="' + lid + '"]');
        if (target) {
          expandGroup(target);
          try { target.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch(e) { target.scrollIntoView(true); }
        }
      }
    } else if (isSeeAll) {
      var crrId = btn.getAttribute('data-jump-crr-id') || '';
      if (crrId) {
        var crrEl = document.getElementById('crr-' + crrId);
        if (crrEl) {
          try { crrEl.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch(e) { crrEl.scrollIntoView(true); }
          return;
        }
        // Fallback: scroll to lottery group
        var grpFb = document.querySelector('.lottery-group[data-lottery-id="' + crrId + '"]');
        if (grpFb) {
          expandGroup(grpFb);
          try { grpFb.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch(e) { grpFb.scrollIntoView(true); }
        }
      }
    }
  });
})();

// =========================================================
// "Use these settings" handler for Best Opportunities panel
// Scrolls to the lottery group, expands it, and highlights
// the relevant run card so the user sees it immediately.
// =========================================================
document.addEventListener('click', function(ev) {
  var btn = ev.target ? ev.target.closest('[data-use-settings]') : null;
  if (!btn) return;

  var lotteryId = btn.getAttribute('data-lottery-id') || '';
  var runId     = btn.getAttribute('data-run-id') || '';
  if (!lotteryId) return;

  // Prefer exact group-key (lotteryId-YYYY-MM-DD) to avoid landing on the wrong draw
  var groupKey = btn.getAttribute('data-group-key') || '';

  var target = null;
  if (groupKey) {
    target = document.querySelector('.lottery-group[data-lottery-id="' + groupKey + '"]');
  }

  // Fallback: Find the lottery group by lottery_id prefix (legacy behavior)
  if (!target) {
    var groups = document.querySelectorAll('.lottery-group[data-lottery-id]');
    Array.prototype.forEach.call(groups, function(g) {
      var gLid = (g.getAttribute('data-lottery-id') || '').split('-')[0];
      if (gLid === String(lotteryId) && !target) target = g;
    });
  }

  if (!target) return;
  // Expand the group if collapsed
  if (target.classList.contains('collapsed')) {
    target.classList.remove('collapsed');
    var tog = target.querySelector('.lottery-collapse-toggle');
    if (tog) {
      tog.textContent = 'Collapse';
      tog.setAttribute('aria-expanded', 'true');
      tog.setAttribute('aria-label', 'Collapse lottery section');
      try { localStorage.setItem('lottery_collapsed_' + target.getAttribute('data-lottery-id'), 'false'); } catch(e) {}
    }
  }

  // Scroll to the group
  try {
    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
  } catch(e) {
    target.scrollIntoView(true);
  }

  // Highlight the specific run card if we have a run_id
  if (runId) {
    var runCard = target.querySelector('[data-set-id="' + runId + '"]');
    if (runCard) {
      runCard.style.transition = 'box-shadow 0.3s ease, outline 0.3s ease';
      runCard.style.outline = '3px solid #1C66FF';
      runCard.style.boxShadow = '0 0 0 4px rgba(28,102,255,0.18)';
      setTimeout(function() {
        runCard.style.outline = '';
        runCard.style.boxShadow = '';
      }, 2800);
    }
  }
});

});
[[/script]]

{/source}