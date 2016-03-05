<?php
/**
 * @package org.openpsa.user
 * @author CONTENT CONTROL http://www.contentcontrol-berlin.de/
 * @copyright CONTENT CONTROL http://www.contentcontrol-berlin.de/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 */

/**
 * org.openpsa.contacts person handler and viewer class.
 *
 * @package org.openpsa.user
 */
class org_openpsa_user_handler_person_privileges extends midcom_baseclasses_components_handler
implements midcom_helper_datamanager2_interfaces_edit
{
    /**
     * The person we're working with, if any
     *
     * @var midcom_db_person
     */
    private $_person = null;

    /**
     * Loads and prepares the schema database.
     *
     * The operations are done on all available schemas within the DB.
     */
    public function load_schemadb()
    {
        $schemadb = midcom_helper_datamanager2_schema::load_database($this->_config->get('schemadb_acl'));

        $fields =& $schemadb['default']->fields;
        $user_object = midcom::get()->auth->get_user($this->_person->guid);

        $person_object = $user_object->get_storage();

        // Get the calendar root event
        $root_event = org_openpsa_calendar_interface::find_root_event();
        if (is_object($root_event))
        {
            $fields['calendar']['privilege_object'] = $root_event;
            $fields['calendar']['privilege_assignee'] = $user_object->id;
        }
        else
        {
            unset($fields['calendar']);
        }

        $fields['contact_creation']['privilege_object'] =  $person_object;
        $fields['contact_editing']['privilege_object'] =  $person_object;

        $fields['organization_creation']['privilege_object'] = $person_object;
        $fields['organization_editing']['privilege_object'] = $person_object;

        $fields['projects']['privilege_object'] = $person_object;
        $fields['invoices_creation']['privilege_object'] = $person_object;
        $fields['invoices_editing']['privilege_object'] = $person_object;

        $fields['products_creation']['privilege_object'] = $person_object;
        $fields['products_editing']['privilege_object'] = $person_object;

        $fields['wiki_creation']['privilege_object'] = $person_object;
        $fields['wiki_editing']['privilege_object'] = $person_object;

        $fields['campaigns_creation']['privilege_object'] = $person_object;
        $fields['campaigns_editing']['privilege_object'] = $person_object;

        $fields['salesproject_creation']['privilege_object'] = $person_object;
        return $schemadb;
    }

    /**
     * @param mixed $handler_id The ID of the handler.
     * @param array $args The argument list.
     * @param array &$data The local request data.
     */
    public function _handler_privileges($handler_id, array $args, array &$data)
    {
        $this->_person = new midcom_db_person($args[0]);
        $this->_person->require_do('midgard:privileges');

        midcom::get()->head->set_pagetitle($this->_l10n->get("permissions"));

        $workflow = $this->get_workflow('datamanager2', array('controller' => $this->get_controller('simple', $this->_person)));
        return $workflow->run();
    }
}
