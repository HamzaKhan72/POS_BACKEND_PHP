<?php
// Gmail SMTP settings used to send the password-reset code.
//
// How to get a Gmail "App Password":
//   1. Open https://myaccount.google.com/security and turn ON 2-Step Verification.
//   2. Open https://myaccount.google.com/apppasswords, create an app password (name: "POS").
//   3. Paste the 16-character password below (spaces are ignored).
return [
    'host'       => 'smtp.gmail.com',
    'port'       => 465,
    'username'   => 'yourshop@gmail.com',      // the Gmail account that SENDS the codes
    'password'   => 'xxxx xxxx xxxx xxxx',     // Gmail App Password (NOT your normal password)
    'from_email' => 'yourshop@gmail.com',
    'from_name'  => 'SWIFT SIGN POS',
    // If sending fails with an SSL certificate error on XAMPP/Windows, set this to false.
    'verify_ssl' => true,
];