<?php
/**
 * @package openpsa.test
 * @author CONTENT CONTROL http://www.contentcontrol-berlin.de/
 * @copyright CONTENT CONTROL http://www.contentcontrol-berlin.de/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 */

/**
 * OpenPSA testcase
 *
 * @package openpsa.test
 */
class org_openpsa_documents_handler_finderTest extends openpsa_testcase
{
    protected static $_person;

    public static function setUpBeforeClass() : void
    {
        self::$_person = self::create_user(true);
    }

    public function testHandler_view()
    {
        midcom::get()->auth->request_sudo('org.openpsa.documents');

        $data = $this->run_handler('org.openpsa.documents', []);
        $this->assertEquals('finder-view', $data['handler_id']);

        midcom::get()->auth->drop_sudo();
    }
}
