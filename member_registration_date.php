// add new column 'Registered'
function registrationdate($columns) {
    $columns['registrationdate'] = __('Registered', 'registrationdate');
    return $columns;
}
add_filter('manage_users_columns', 'registrationdate');

// get registration date 
function registrationdate_columns( $value, $column_name, $user_id ) {
        if ( 'registrationdate' != $column_name )
           return $value;
        $user = get_userdata( $user_id );
        $registrationdate = $user->user_registered;
        //$registrationdate = date("Y-m-d", strtotime($registrationdate));
        return $registrationdate;
}
add_action('manage_users_custom_column',  'registrationdate_columns', 10, 3);

// add sorting functionality
function registrationdate_column_sortable($columns) {
          $custom = array(
      // meta column id => sortby value used in query
          'registrationdate'    => 'registered',
          );
      return wp_parse_args($custom, $columns);
}

add_filter( 'manage_users_sortable_columns', 'registrationdate_column_sortable' );

//add order_by functionality
function registrationdate_column_orderby( $vars ) {
        if ( isset( $vars['orderby'] ) && 'registrationdate' == $vars['orderby'] ) {
                $vars = array_merge( $vars, array(
                        'meta_key' => 'registrationdate',
                        'orderby' => 'meta_value'
                ) );
        }

        return $vars;
}

add_filter( 'request', 'registrationdate_column_orderby' );