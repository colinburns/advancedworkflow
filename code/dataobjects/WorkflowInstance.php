<?php
/**
 * A WorkflowInstance is created whenever a user 'starts' a workflow. 
 * 
 * This 'start' is triggered automatically when the user clicks the relevant 
 * button (eg 'apply for approval'). This creates a standalone object
 * that maintains the state of the workflow process. 
 * 
 *
 * @author  marcus@silverstripe.com.au
 * @license BSD License (http://silverstripe.org/bsd-license/)
 * @package advancedworkflow
 */
class WorkflowInstance extends DataObject {
    public static $db = array(
		'Title' => 'Varchar(128)',
		'WorkflowStatus' => "Enum('Active,Paused,Complete,Cancelled','Active')",
		'TargetClass' => 'Varchar(64)',
		'TargetID' => 'Int',
	);

	public static $has_one = array(
		'Definition'    => 'WorkflowDefinition',
		'CurrentAction' => 'WorkflowActionInstance',
		'Initiator'		=> 'Member',
	);

	public static $has_many = array(
		'Actions' => 'WorkflowActionInstance',
	);

	/**
	 * The list of users who are responsible for performing the current WorkflowAction
	 *
	 * @var array
	 */
	public static $many_many = array(
		'Users' => 'Member',
		'Groups' => 'Group'
	);

	public static $summary_fields = array(
		'Title',
		'WorkflowStatus',
		'Created'
	);

	/**
	 * Returns a table that summarises all the actions performed as part of this instance.
	 *
	 * @return FieldSet
	 */
	public function getActionsSummaryFields() {
		return new FieldSet(new TabSet('Root', new Tab('Actions', new TableListField(
			'WorkflowActions',
			'WorkflowActionInstance',
			array(
				'BaseAction.Title' => 'Title',
				'Comment'          => 'Comment',
				'Created'          => 'Date',
				'Member.Name'      => 'Author'
			),
			'"Finished" = 1 AND "WorkflowID" = ' . $this->ID
		))));
	}

	/**
	 * Get the object that this workflow is active for.
	 *
	 * Because workflows might not just be on sitetree items, we
	 * need to account for being attached to anything
	 */
	public function getTarget() {
		if ($this->TargetID) {
			return DataObject::get_by_id($this->TargetClass, $this->TargetID);
		}
	}

	/**
	 * Start a workflow based on a particular definition for a particular object.
	 *
	 * The object is optional; if not specified, it is assumed that this workflow
	 * is simply a task based checklist type of workflow.
	 *
	 * @param WorkflowDefinition $definition
	 * @param DataObject $for
	 */
	public function beginWorkflow(WorkflowDefinition $definition, DataObject $for=null) {
		if(!$this->ID) {
			$this->write();
		}

		if ($for && ($for->hasExtension('WorkflowApplicable') || $for->hasExtension('FileWorkflowApplicable'))) {
			$this->TargetClass = $for->ClassName;
			$this->TargetID = $for->ID;
		}

		// lets create the first WorkflowActionInstance. 
		$action = $definition->getInitialAction()->getInstanceForWorkflow();
		$action->WorkflowID   = $this->ID;
		$action->write();

		$this->Title = sprintf(_t('WorkflowInstance.TITLE_STUB', 'Instance #%s of %s'), $this->ID, $definition->Title);
		$this->DefinitionID    = $definition->ID;
		$this->CurrentActionID = $action->ID;
		$this->InitiatorID = Member::currentUserID();
		$this->write();

		$this->Users()->addMany($definition->Users());
		$this->Groups()->addMany($definition->Groups());
	}

	/**
	 * Execute this workflow. In rare cases this will actually execute all actions,
	 * but typically, it will stop and wait for
	 */
	public function execute() {
		if (!$this->CurrentActionID) {
			throw new Exception("Attempted to start an invalid workflow instance #$this->ID!");
		}

		$action     = $this->CurrentAction();
		$transition = false;

		// if the action has already finished, it means it has either multiple (or no
		// transitions at the time), so a subsequent check should be run.
		if($action->Finished) {
			$transition = $this->checkTransitions($action);
		} else {
			$result = $action->BaseAction()->execute($this);

			// if the action was successful, then the action has finished running and
			// next transition should be run - otherwise wait for more time or user
			// input.
			if($result) {
				$action->Finished = true;
				$action->MemberID = Member::currentUserID();
				$action->write();

				$transition = $this->checkTransitions($action);
			}
		}

		// if the action finished, and there's only one available transition then
		// move onto that step - otherwise check if the workflow has finished.
		if($transition) {
			$this->performTransition($transition);
		} else {
			// see if there are any transitions available, even if they are not valid.
			if($action->Finished && !count($action->BaseAction()->Transitions())) {
				$this->WorkflowStatus  = 'Complete';
				$this->CurrentActionID = 0;
			} else {
				$this->WorkflowStatus = 'Paused';
			}

			$this->write();
		}
	}

	/**
	 * Evaluate all the transitions of an action and determine whether we should
	 * follow any of them yet.
	 *
	 * @param  WorkflowActionInstance $action
	 * @return WorkflowTransition
	 */
	protected function checkTransitions(WorkflowActionInstance $action) {
		$transitions = $action->getValidTransitions();
		// if there's JUST ONE transition, then we need should
		// immediately follow it.
		if ($transitions && $transitions->Count() == 1) {
			return $transitions->First();
		}
	}

	/**
	 * Transitions a workflow to the next step defined by the given transition.
	 *
	 * After transitioning, the action is 'executed', and next steps
	 * determined. 
	 *
	 * @param WorkflowTransition $transition
	 */
	public function performTransition(WorkflowTransition $transition) {
		$definition = DataObject::get_by_id('WorkflowAction', $transition->NextActionID);
		$action = $definition->getInstanceForWorkflow();
		$action->WorkflowID   = $this->ID;
		$action->write();

		$this->CurrentActionID = $action->ID;
		$this->write();
		$this->components = array(); // manually clear the has_one cache

		$transition->extend('onTransition');
		$this->execute();
	}

	/**
	 * Returns a set of all Members that are assigned to this instance, either directly or via a group.
	 *
	 * @todo   This could be made more efficient.
	 * @return DataObjectSet
	 */
	public function getAssignedMembers() {
		$members = $this->Users();
		$groups  = $this->Groups();

		foreach($groups as $group) {
			$members->merge($group->Members());
		}

		$members->removeDuplicates();
		return $members;
	}

	public function canView($member=null) {
		return $this->userHasAccess($member);
	}
	public function canEdit($member=null) {
		return $this->userHasAccess($member);
	}
	public function canDelete($member=null) {
		return $this->userHasAccess($member);
	}

	/**
	 * Checks whether the given user is in the list of users assigned to this
	 * workflow
	 *
	 * @param $memberID
	 */
	protected function userHasAccess($member) {
		if (!$member) {
			if (!Member::currentUserID()) {
				return false;
			}
			$member = Member::currentUser();
		}

		if(Permission::checkMember($member, "ADMIN")) {
			return true;
		}

		return $member->inGroups($this->Groups()) || $this->Users()->find('ID', $member->ID);
	}

	/**
	 * Can documents in the current workflow state be edited?
	 */
	public function canEditTarget() {
		if ($this->CurrentActionID) {
			return $this->CurrentAction()->canEditTarget($this->getTarget());
		}
	}

	/**
	 * Does this action restrict viewing of the document?
	 *
	 * @return boolean
	 */
	public function canViewTarget() {
		$action = $this->CurrentAction();
		if ($action) {
			return $action->canViewTarget($this->getTarget());
		}
		return true;
	}

	/**
	 * Does this action restrict the publishing of a document?
	 *
	 * @return boolean
	 */
	public function canPublishTarget() {
		if ($this->CurrentActionID) {
			return $this->CurrentAction()->canPublishTarget($this->getTarget());
		}
	}
	
	
	/* UI RELATED METHODS */

	/**
	 * Gets fields for managing this workflow instance in its current step
	 */
	public function getWorkflowFields() {
		$action    = $this->CurrentAction();
		$options   = $action->getValidTransitions();
		$wfOptions = $options->map('ID', 'Title', ' ');
		$fields    = new FieldSet();

		$fields->push(new HeaderField('WorkflowHeader', $action->Title));
		$fields->push(new DropdownField('TransitionID', _t('WorkflowApplicable.NEXT_ACTION', 'Next Action'), $wfOptions));
		$action->BaseAction()->updateWorkflowFields($fields);
		
		return $fields;
	}
}
