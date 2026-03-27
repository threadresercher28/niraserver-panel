<?php
if (constant('CONFIG_RUNNER') == "PERMISSION_DATABASE") {
    define('database_server', '.....');
    define('database_login', '.......');
    define('database_password', '........');
    define('database_name', '...........');
    define('table_users', '.............');
    define('table_stream_source','.........');
}

$epg_Master = ['https://iptvx.one/epg/epg_lte.xml.gz'];
