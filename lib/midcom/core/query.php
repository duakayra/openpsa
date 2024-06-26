<?php
/**
 * @package midcom
 * @author CONTENT CONTROL http://www.contentcontrol-berlin.de/
 * @copyright CONTENT CONTROL http://www.contentcontrol-berlin.de/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 */

use Doctrine\ORM\Query\Expr\Composite;
use Doctrine\ORM\QueryBuilder;

/**
 * Common base class for collector and querybuilder
 *
 * @package midcom
 */
abstract class midcom_core_query
{
    /**
     * Set this element to true to hide all items which are currently invisible according
     * to the approval/scheduling settings made using Metadata. This must be set before executing
     * the query.
     *
     * NOTE: Approval checks not implemented in collector yet
     *
     * Be aware, that this setting will currently not use the QB to filter the objects accordingly,
     * since there is no way yet to filter against parameters. This will mean some performance
     * impact.
     */
    public bool $hide_invisible = true;

    /**
     * Flag that tracks whether deleted visibility check have already been added
     */
    protected bool $_visibility_checks_added = false;

    /**
     * The number of records to return to the client at most.
     */
    protected int $_limit = 0;

    /**
     * The offset of the first record the client wants to have available.
     */
    protected int $_offset = 0;

    /**
     * Type that the application expects to retrieve from this instance.
     */
    protected string $_real_class;

    /**
     * The number of records found by the last execute() run. This is -1 as long as no
     * query has been executed. This member is read-only.
     */
    protected int $count = -1;

    /**
     * The query backend, should be set in constructor. Currently collector or querybuilder
     */
    protected \midgard\portable\query $_query;

    /**
     * The number of objects for which access was denied.
     */
    public int $denied = 0;

    /**
     * User id for ACL checks. This is set when executing to avoid unnecessary overhead
     */
    private ?string $_user_id = null;

    /**
     * Class resolution into the MidCOM DBA system.
     * Currently, Midgard requires the actual MgdSchema base classes to be used
     * when dealing with the query, so we internally note the corresponding class
     * information to be able to do correct typecasting later.
     *
     * @return string MgdSchema class name
     */
    protected function _convert_class(string $classname) : string
    {
        $this->_real_class = $classname;
        $mgdschema_class = midcom::get()->dbclassloader->get_mgdschema_class_name_for_midcom_class($classname);
        if (!$mgdschema_class) {
            throw new midcom_error(
                "Cannot create a midcom_core_query instance for the type {$classname}: Does not seem to be a DBA class name."
            );
        }
        return $mgdschema_class;
    }

    protected function is_readable(string $guid) : bool
    {
        return !$this->_user_id
            || midcom::get()->auth->acl->can_do_byguid('midgard:read', $guid, $this->_real_class, $this->_user_id);
    }

    protected function _add_visibility_checks()
    {
        if (!midcom::get()->auth->admin) {
            $this->_user_id = midcom::get()->auth->acl->get_user_id();
        }

        if (   $this->hide_invisible
            && !$this->_visibility_checks_added) {
            if (!midcom::get()->config->get('show_hidden_objects')) {
                $this->add_constraint('metadata.hidden', '=', false);
                $now = date('Y-m-d H:i:s');
                $this->add_constraint('metadata.schedulestart', '<=', $now);
                $this->begin_group('OR');
                    $this->add_constraint('metadata.scheduleend', '>=', $now);
                    $this->add_constraint('metadata.scheduleend', '<=', '0001-01-01 00:00:00');
                $this->end_group();
            }

            if (!midcom::get()->config->get('show_unapproved_objects')) {
                $this->add_constraint('metadata.isapproved', '=', true);
            }
            $this->_visibility_checks_added = true;
        }
    }

    /**
     * Resets some internal variables for re-execute
     */
    protected function _reset()
    {
        $this->count = -1;
        $this->denied = 0;
    }

    protected function prepare_execute() : bool
    {
        $ret = $this->_real_class::_on_execute($this);
        if (!$ret) {
            debug_add('The _on_execute callback returned false');
        }
        return $ret;
    }

    public function get_doctrine() : QueryBuilder
    {
        return $this->_query->get_doctrine();
    }

    public function get_current_group() : Composite
    {
        return $this->_query->get_current_group();
    }

    /**
     * Add a constraint to the query.
     *
     * @param string $operator The operator to use for the constraint, currently supported are
     *     <, <=, =, <>, >=, >, LIKE. LIKE uses the percent sign ('%') as a
     *     wildcard character.
     * @param mixed $value The value to compare against. It should be of the same type as the
     *     queried property.
     */
    public function add_constraint(string $field, string $operator, $value)
    {
        $this->_reset();
        // Add check against null values, Core MC is too stupid to get this right.
        if ($value === null) {
            throw new midcom_error("Cannot add constraint on field '{$field}' with null value.");
        }
        // Deal with empty arrays, which would produce invalid queries
        // This is done here to avoid repetitive code in callers, and because
        // it's easy enough to generalize: IN empty set => always false, NOT IN empty set => always true
        if ($value === []) {
            if ($operator == 'NOT IN') {
                return;
            }
            if ($operator == 'IN') {
                $this->add_constraint('id', '=', 0);
                return;
            }
        }
        if (!$this->_query->add_constraint($field, $operator, $value)) {
            debug_add("Class = '{$this->_real_class}, Field = '{$field}', Operator = '{$operator}'");
            throw new midcom_error("Failed to execute add_constraint: " . midcom_connection::get_error_string());
        }
    }

    /**
     * Add a constraint against another DB column to the query.
     *
     * @param string $operator The operator to use for the constraint, currently supported are
     *     <, <=, =, <>, >=, >, LIKE. LIKE uses the percent sign ('%') as a
     *     wildcard character.
     * @param string $compare_field The field to compare against.
     */
    public function add_constraint_with_property(string $field, string $operator, string $compare_field)
    {
        $this->_reset();
        if (!$this->_query->add_constraint_with_property($field, $operator, $compare_field)) {
            debug_add("Class = '{$this->_real_class}, Field = '{$field}', Operator = '{$operator}', compare_field: '{$compare_field}'");
            throw new midcom_error("Failed to execute add_constraint_with_property: " . midcom_connection::get_error_string());
        }
    }

    /**
     * Creates a new logical group within the query. They are set in parentheses in the final
     * SQL and will thus be evaluated with precedence over the normal out-of-group constraints.
     *
     * While the call lets you decide whether all constraints within the group are AND'ed or OR'ed,
     * only OR constraints make logically sense in this context, which is why this proxy function
     * sets 'OR' as the default operator.
     *
     * @param string $operator One of 'OR' or 'AND' denoting the logical operation with which all
     *     constraints in the group are concatenated.
     */
    public function begin_group(string $operator)
    {
        if (!$this->_query->begin_group($operator)) {
            throw new midcom_error("Failed to execute begin_group {$operator}");
        }
    }

    /**
     * Ends a group previously started with begin_group().
     */
    public function end_group()
    {
        if (!$this->_query->end_group()) {
            throw new midcom_error("Failed to execute end_group");
        }
    }

    /**
     * Limits the resultset to contain at most the specified number of records.
     * Set the limit to zero to retrieve all available records.
     */
    public function set_limit(int $limit)
    {
        $this->_reset();
        $this->_limit = $limit;
    }

    /**
     * Sets the offset of the first record to retrieve. This is a zero based index,
     * so if you want to retrieve from the very first record, the correct offset would
     * be zero, not one.
     */
    public function set_offset(int $offset)
    {
        $this->_reset();

        $this->_offset = $offset;
    }

    /**
     * Add an ordering constraint to the query builder.
     *
     * @param string $direction One of 'ASC' or 'DESC' indicating ascending or descending
     *     ordering. The default is 'ASC'.
     */
    public function add_order(string $field, string $direction = 'ASC')
    {
        if (!$this->_query->add_order($field, $direction)) {
            throw new midcom_error("Failed to execute add_order for column '{$field}': " . midcom_connection::get_error_string());
        }
    }

    /**
     * Get the DBA class we're currently working on
     */
    public function get_classname() : string
    {
        return $this->_real_class;
    }

    abstract public function execute();

    abstract public function count();

    abstract public function count_unchecked();
}
