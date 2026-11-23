export const defaultTerminology = {
    person_singular: 'Person', person_plural: 'People',
    group_singular: 'Group', group_plural: 'Groups',
    subgroup_singular: 'Subgroup', subgroup_plural: 'Subgroups',
};

export function terminologyFor(user) {
    return { ...defaultTerminology, ...(user?.organization?.terminology || {}) };
}
