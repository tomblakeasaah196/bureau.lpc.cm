#!/bin/bash
echo "Starting LPC ERP Environment Setup for bureau.lpc.cm..."

BASE_DIR="."

mkdir -p $BASE_DIR/assets/{css,js,img,fonts}
mkdir -p $BASE_DIR/includes/{config,classes,functions,components}
mkdir -p $BASE_DIR/modules/{auth,dashboard,sales,accounting,inventory,hr,fleet}
mkdir -p $BASE_DIR/api/v1
mkdir -p $BASE_DIR/uploads/{invoices,reports,profiles}

touch $BASE_DIR/index.php
touch $BASE_DIR/.htaccess
touch $BASE_DIR/includes/config/db.php
touch $BASE_DIR/includes/config/constants.php
touch $BASE_DIR/includes/classes/Auth.php
touch $BASE_DIR/includes/classes/Database.php
touch $BASE_DIR/includes/classes/Accounting.php
touch $BASE_DIR/includes/functions/helpers.php

cat <<EOT >> $BASE_DIR/.htaccess
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php?route=$1 [QSA,L]
RewriteRule ^includes/ - [F,L]
EOT

cat <<EOT >> $BASE_DIR/uploads/.htaccess
php_flag engine off
Options -ExecCGI
EOT

echo "Subdomain directory structure created successfully!"
