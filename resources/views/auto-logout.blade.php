<script>
    let inactivityTime = function () {
        let timer;
        const logoutAfter = 180000; // 3 minutes

        function logoutUser() {
            fetch("{{ route('auto.logout') }}", {
                method: "POST",
                headers: {
                    "X-CSRF-TOKEN": "{{ csrf_token() }}",
                    "Content-Type": "application/json"
                }
            }).then(() => {
                window.location.href = "{{ route('login') }}";
            });
        }

        function resetTimer() {
            clearTimeout(timer);
            timer = setTimeout(logoutUser, logoutAfter);
        }

        // Events that mean user is active
        window.onload = resetTimer;
        document.onmousemove = resetTimer;
        document.onkeypress = resetTimer;
        document.onscroll = resetTimer;
        document.onclick = resetTimer;
    };
    inactivityTime();
</script>
