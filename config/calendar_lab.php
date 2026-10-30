<?php

return [
    'main_enabled'=>env('GOOGLE_CALENDAR_MAIN_ENABLED', false),
    'enabled'=>env('GOOGLE_CALENDAR_LAB_ENABLED', false),
    'client_id'=>env('GOOGLE_CALENDAR_CLIENT_ID'),
    'client_secret'=>env('GOOGLE_CALENDAR_CLIENT_SECRET'),
    'redirect_uri'=>env('GOOGLE_CALENDAR_REDIRECT_URI', rtrim(env('APP_URL','http://localhost'),'/').'/calendar-lab/callback'),
];
