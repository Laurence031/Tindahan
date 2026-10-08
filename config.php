<?php
/**
 * TINDAHAN - config
 * Dito mo lang papalitan ang settings. Hindi kailangang galawin ang ibang PHP files.
 */
return [
    // 'firebase' = ang totoong database mo | 'local' = data/db.json lang (pang-test, walang internet)
    'storage'       => getenv('TINDAHAN_STORAGE') ?: 'firebase',

    // Realtime Database URL mo (walang slash sa dulo)
    'firebase_url'  => getenv('TINDAHAN_FIREBASE_URL') ?: 'https://dict-project-witi-default-rtdb.asia-southeast1.firebasedatabase.app',

    // OPTIONAL pero RECOMMENDED: Database secret (Firebase Console > Project settings >
    // Service accounts > Database secrets). Kapag nilagay mo ito, pwede mong gawing
    // ".read": false, ".write": false ang rules, at PHP lang ang makaka-access.
    'firebase_auth' => getenv('TINDAHAN_FIREBASE_AUTH') ?: '',

    // Dito sine-save ang JSON copy ng buong database (nare-refresh after every write)
    'json_file'     => getenv('TINDAHAN_DB_FILE') ?: __DIR__ . '/../data/db.json',

    'timezone'      => 'Asia/Manila',

    // Pang-demo: nagpapagana ng "Fill demo attendance" sa owner Payroll tab.
    // Gawing false kapag live na.
    'enable_demo_seed' => true,

    // Allowed origin para sa CORS (kung hiwalay ang port ng HTML at PHP, e.g. Live Server). Palitan ng domain mo kapag live.
    'allow_origin'  => '*',

    // Low-stock threshold (para sa notifications)
    'default_reorder' => 10,
];
