{{-- Reload the Allocate Users list when the warehouse branch changes.
     The server list is branch-filtered (branch grants, pos_branch_id,
     or no branch assignment = all branches). Users already selected
     are always kept, so changing the branch can never silently drop
     an allocation. Any load or rebuild failure leaves the current
     list untouched, so the modal keeps working. --}}
<script>
(function () {
    var modal = document.getElementById('commonModal');
    if (!modal) {
        return;
    }
    var branchSelect = modal.querySelector('select[name="branch_id"]');
    var userSelect = modal.querySelector('#assigned_users');
    if (!branchSelect || !userSelect || branchSelect.dataset.warehouseUsersBound) {
        return;
    }
    branchSelect.dataset.warehouseUsersBound = '1';
    branchSelect.addEventListener('change', function () {
        var branchId = branchSelect.value || '';
        var url = '{{ route('warehouse.users') }}' + (branchId ? '?branch_id=' + encodeURIComponent(branchId) : '');
        var selected = {};
        Array.prototype.forEach.call(userSelect.options, function (opt) {
            if (opt.selected) {
                selected[opt.value] = opt.text;
            }
        });
        fetch(url, {headers: {'X-Requested-With': 'XMLHttpRequest'}})
            .then(function (r) {
                if (!r.ok) {
                    throw new Error('users load failed');
                }
                return r.json();
            })
            .then(function (users) {
                try {
                    var choices = [];
                    var seen = {};
                    users.forEach(function (u) {
                        seen[String(u.id)] = true;
                        choices.push({value: String(u.id), label: u.label, selected: !!selected[String(u.id)]});
                    });
                    Object.keys(selected).forEach(function (id) {
                        if (!seen[id]) {
                            choices.push({value: id, label: selected[id], selected: true});
                        }
                    });
                    var instance = window.warehouseAssignedUsersChoices;
                    if (instance && typeof instance.clearStore === 'function' && typeof instance.setChoices === 'function') {
                        instance.clearStore();
                        instance.setChoices(choices, 'value', 'label', false);
                    } else {
                        userSelect.innerHTML = '';
                        choices.forEach(function (c) {
                            var o = document.createElement('option');
                            o.value = c.value;
                            o.text = c.label;
                            o.selected = c.selected;
                            userSelect.appendChild(o);
                        });
                    }
                } catch (e) {
                    /* leave the existing list untouched */
                }
            })
            .catch(function () {
                /* leave the existing list untouched */
            });
    });
})();
</script>
