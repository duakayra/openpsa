<?php
/**
 * @package fi.protie.navigation
 * @author The Midgard Project, http://www.midgard-project.org
 * @version $Id: viewer.php 3975 2006-09-06 17:36:03Z bergie $
 * @copyright The Midgard Project, http://www.midgard-project.org
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 */

 
/**
 * Versatile class for drawing dynamically navigation elements according to
 * user preferences.
 * 
 * @package fi.protie.navigation
 */
class fi_protie_navigation_interface extends midcom_baseclasses_components_interface
{
    
    function __construct()
    {
        parent::__construct();
        
        $this->_component = 'fi.protie.navigation';
        $this->_autoload_files = array();
    }
    
    function _on_initialize()
    {
        return true;
    }
}


?>