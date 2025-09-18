<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the website, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'audioai' );

/** Database username */
define( 'DB_USER', 'root' );

/** Database password */
define( 'DB_PASSWORD', '' );

/** Database hostname */
define( 'DB_HOST', 'localhost' );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8mb4' );

/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',         'h0%vHjobJ^J4+sfz)0]Wms+R0ua3pZdEob^<C@aE,,Oqb&ZL1/`|@WOdnwYbxOQ)' );
define( 'SECURE_AUTH_KEY',  'S0.ay{5KHO(cdh}wMSLjh%gi|kpUHq3mOYq/xy)?gxy(h$?>@ffU;c(5d--RBurl' );
define( 'LOGGED_IN_KEY',    '.C<}}I`s5ffZ!L1Fwd;Md$-pNsDY6WD.reUYZleP`Jom@*{%Ltvqp0L>SxU7G<j(' );
define( 'NONCE_KEY',        'CDY;+&_:p}IvR/@^CkWq5|Cjqz)~^p2LOBML}2aZxnF2ff&t}V?:0UEgA)vI/3~.' );
define( 'AUTH_SALT',        'Nn@;|%$#3qlM|vW>[~B1ID QA(lxBSo>HTa-F9juTXx2=nKL9bcz{3|[CDz|Wsym' );
define( 'SECURE_AUTH_SALT', 'dl]`dp2CNiuITyyFR+~DF#CaL6B5LOkbFMfBRS4=D%U[c>F,N2a>Ji2QG_p%S<]=' );
define( 'LOGGED_IN_SALT',   'PNcF|n7ZCa*WsRV (JCQ5b2aTcNL59$~6[$R+5q 6sIcno)5&U5j32TZ-E)3NE m' );
define( 'NONCE_SALT',       'qpUpGm7fru&)}+P!k_-oy).n[gX^$)6n5|f`IAd51w?_ yr<{nwpG}_A__r%%c;I' );

/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 *
 * At the installation time, database tables are created with the specified prefix.
 * Changing this value after WordPress is installed will make your site think
 * it has not been installed.
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/#table-prefix
 */
$table_prefix = 'wpai_';

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://developer.wordpress.org/advanced-administration/debug/debug-wordpress/
 */
define( 'WP_DEBUG', false );

/* Add any custom values between this line and the "stop editing" line. */



/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
