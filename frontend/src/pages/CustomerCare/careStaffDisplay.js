const isAssignmentForCustomerCare = (assignment, customerCare) =>
  assignment?.customer_care_id != null
  && customerCare?.id != null
  && String(assignment.customer_care_id) === String(customerCare.id)

const getCurrentAssignment = (customerCare) => {
  const assignment = customerCare?.current_assignment

  return assignment?.status === 'active'
    && assignment.cared_at == null
    && isAssignmentForCustomerCare(assignment, customerCare)
    ? assignment
    : null
}

const getCompletedAssignment = (customerCare) => {
  const assignment = customerCare?.active_assignment

  return String(customerCare?.status) === '1'
    && assignment?.status === 'active'
    && assignment.cared_at != null
    && isAssignmentForCustomerCare(assignment, customerCare)
    ? assignment
    : null
}

/**
 * Resolves staff text for display only. It must never be used to decide
 * current assignment ownership or whether a care completion is permitted.
 */
export const getCareStaffDisplay = (customerCare) => {
  if (customerCare?.current_assignment_ambiguous) {
    return { kind: 'ambiguous' }
  }

  const currentAssignment = getCurrentAssignment(customerCare)
  if (currentAssignment) {
    return { kind: 'current', assignment: currentAssignment }
  }

  const completedAssignment = getCompletedAssignment(customerCare)
  if (completedAssignment) {
    return { kind: 'completed', assignment: completedAssignment }
  }

  const legacyName = customerCare?.user_care?.name
  if (legacyName) {
    return { kind: 'legacy', name: legacyName }
  }

  return { kind: 'none' }
}
