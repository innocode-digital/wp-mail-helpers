<?php

namespace Innocode\Mail\Helpers;

/**
 * Class Plugin
 * @package Innocode\Mail\Helpers
 */
final class Plugin
{
    const OPTION_GROUP = 'innocode_mail_helpers';

    /**
     * @var string
     */
    private $path;
    /**
     * @var array
     */
    private $options = [];
    /**
     * @var Admin
     */
    private $admin;

    /**
     * Plugin constructor.
     * @param string $path
     */
    public function __construct( $path )
    {
        $this->path = $path;
        $admin = new Admin(
            [ $this, 'option' ],
            [ $this, 'view' ]
        );
        $this->options['from_address'] = new Option( 'from_address' );
        $this->options['hash'] = new Option( 'hash' );

        foreach ( array_keys( $admin->get_settings() ) as $name ) {
            $this->options[ $name ] = new Option( $name );
        }

        $this->admin = $admin;
    }

    /**
     * Adds hooks
     */
    public function run()
    {
        $admin = $this->get_admin();

        if ( is_multisite() ) {
            add_action( 'admin_init', [ $admin, 'register_settings' ] );
        }

        add_action( 'admin_menu', [ $admin, 'add_pages' ] );
        add_action( 'admin_init', [ $admin, 'add_sections' ] );
        add_action( 'admin_init', [ $admin, 'add_fields' ] );

        foreach ( $admin->get_pages() as $name => $page ) {
            $type = $name == 'management' ? 'tools' : 'settings';

            add_action(
                "load-{$type}_page_{$page['menu_slug']}",
                [ $this, 'handle_action' ]
            );
        }

        $new_from_address = $this->option( 'new_from_address' );

        add_filter(
            "pre_update_option_{$new_from_address->get_name()}",
            [ $this, 'pre_update_new_from_address' ],
            10, 3
        );

        add_action(
            "add_option_{$new_from_address->get_name()}",
            [ $this, 'update_new_from_address' ],
            10, 2
        );
        add_action(
            "update_option_{$new_from_address->get_name()}",
            [ $this, 'update_new_from_address' ],
            10, 2
        );

        add_filter( 'wp_mail_from_name', [ $this, 'mail_from_name' ], 9999 );
        add_filter( 'wp_mail_from', [ $this, 'mail_from' ], 9999 );
    }

    /**
     * @return string
     */
    public function get_path()
    {
        return $this->path;
    }

    /**
     * @return string
     */
    public function get_views_dir()
    {
        return "{$this->get_path()}/resources/views";
    }

    /**
     * @param string $name
     * @return string
     */
    public function get_view_file( $name )
    {
        return "{$this->get_views_dir()}/$name";
    }

    /**
     * @param string $name
     */
    public function view( $name )
    {
        $file = $this->get_view_file( "$name.php" );

        require_once $file;
    }

    /**
     * @return array
     */
    public function get_options()
    {
        return $this->options;
    }

    /**
     * @param string $name
     * @return Option|null
     */
    public function option( $name )
    {
        $options = $this->get_options();

        return isset( $options[ $name ] ) ? $options[ $name ] : null;
    }

    /**
     * @return Admin
     */
    public function get_admin()
    {
        return $this->admin;
    }

    public function handle_action()
    {
        $admin = $this->get_admin();
        $admin->check_actions_capability();

        switch ( get_current_screen()->id ) {
            case 'settings_page_' . static::OPTION_GROUP:
                $from_address = $this->option( 'from_address' );
                $new_from_address = $this->option( 'new_from_address' );
                $hash = $this->option( 'hash' );

                if ( ! empty( $_GET[ $hash->get_name() ] ) ) {
                    $admin->new_from_address_action(
                        $from_address,
                        $new_from_address,
                        $hash
                    );
                }

                if (
                    ! empty( $_GET['dismiss'] ) &&
                    $new_from_address->get_name() == $_GET['dismiss']
                ) {
                    $admin->dismiss_new_from_address_action( $new_from_address, $hash );
                }

                $admin->check_from_email_domain( $from_address, $new_from_address );

                break;
            case 'tools_page_' . static::OPTION_GROUP . '_tools':
                if ( isset( $_POST['action'] ) && $_POST['action'] == 'test' ) {
                    $admin->send_test_email_action();
                }

                break;
        }
    }

    /**
     * @param $value
     * @param $old_value
     * @param $option
     * @return mixed
     */
    public function pre_update_new_from_address( $value, $old_value, $option )
    {
        if (
            $value &&
            function_exists( 'innocode_mailgun_email_validation' ) &&
            ! innocode_mailgun_email_validation()->is_valid( $value )
        ) {
            if ( function_exists( 'add_settings_error' ) ) {
                add_settings_error(
                    $this->option( $option ),
                    "invalid_{$option}",
                    __( 'The From Email address entered did not appear to be a valid email address. Please enter a valid email address.', 'innocode-mail-helpers' )
                );
            }

            return $old_value;
        }

        $from_address = $this->option( 'from_address' );

        if ( ! $value && $from_address->get() ) {
            $from_address->delete();

            return $value;
        }

        if ( ! $value && $old_value ) {
            return $old_value;
        }

        return $value;
    }

    /**
     * @param $old_value
     * @param $value
     */
    public function update_new_from_address( $old_value, $value )
    {
        if (
            ! $value ||
            ! is_email( $value ) ||
            $value == $this->option( 'from_address' )->get()
        ) {
            return;
        }

        $hash_value = md5( $value . time() . wp_rand() );
        $new_from_address = [
            'hash'     => $hash_value,
            'newemail' => $value,
        ];
        $hash = $this->option( 'hash' );

        update_option( $hash->get_name(), $new_from_address );

        $switched_locale = switch_to_locale( get_user_locale() );

        /* translators: Do not translate ADMIN_URL, EMAIL, SITENAME, SITEURL: those are placeholders. */
        $email_text = __(
            'Howdy,

You recently requested to set the email address from which emails
are sent from.

If this is correct, please click on the following link to change it:
###ADMIN_URL###

You can safely ignore and delete this email if you do not want to
take this action.

This email has been sent to ###EMAIL###

Regards,
All at ###SITENAME###
###SITEURL###',
            'innocode-mail-helpers'
        );

        $content = apply_filters(
            'innocode_mail_helpers_new_from_address_content',
            $email_text,
            $new_from_address
        );
        $content = str_replace(
            '###ADMIN_URL###',
            $this->get_admin()
                ->get_options_url(
                    "{$hash->get_name()}=$hash_value"
                ),
            $content
        );
        $content = str_replace( '###EMAIL###', $value, $content );
        $content = str_replace(
            '###SITENAME###',
            wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES ),
            $content
        );
        $content = str_replace( '###SITEURL###', home_url(), $content );

        wp_mail(
            $value,
            sprintf(
                /* translators: New mail from address notification email subject. %s: Site title. */
                __( '[%s] New Mail From Address' ),
                wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES )
            ),
            $content
        );

        if ( $switched_locale ) {
            restore_previous_locale();
        }
    }

    /**
     * @param string $name
     * @return string
     */
    public function mail_from_name( $name )
    {
        $from_name = $this->option( 'from_name' );
        $value = $from_name->get();

        if ( $value ) {
            return $value;
        }

        $default = $from_name->get_default();

        if ( $default ) {
            return $default;
        }

        return $name;
    }

    /**
     * @param string $email
     * @return string
     */
    public function mail_from( $email )
    {
        $from_address = $this->option( 'from_address' );
        $value = $from_address->get();

        if ( $value && is_email( $value ) ) {
            return $value;
        }

        $default = $from_address->get_default();

        if ( $default && is_email( $default ) ) {
            return $default;
        }

        return $email;
    }
}
