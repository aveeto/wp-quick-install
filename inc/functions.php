<?php

function sanit( $str ) {
	return addcslashes( str_replace( array( ';', "\n" ), '', $str ), '\\' );
}