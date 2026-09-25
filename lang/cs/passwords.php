<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Jazykové řádky pro obnovu hesla
    |--------------------------------------------------------------------------
    |
    | Následující řádky odpovídají důvodům, které vrací broker pro obnovu
    | hesla při pokusu o jeho aktualizaci — třeba kvůli neplatnému tokenu
    | nebo neplatnému heslu.
    |
    */

    'reset' => 'Vaše heslo bylo obnoveno.',
    'sent' => 'Odkaz na obnovu hesla jsme vám poslali e-mailem.',
    'throttled' => 'Počkejte prosím, než to zkusíte znovu.',
    // Stejně jako `PasswordResetController::DUVODY`: neexistující účet se
    // nehlásí jinak než propadlý odkaz, ať nejde zkoušet, kdo tu účet má.
    'token' => 'Odkaz na nové heslo už neplatí — nechte si poslat nový.',
    'user' => 'Odkaz na nové heslo už neplatí — nechte si poslat nový.',

];
