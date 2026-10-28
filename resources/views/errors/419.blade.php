{{-- A form or page that sat open too long: going back loads it again with a fresh token. --}}
<x-errors.screen code="419" icon="heroicon-o-clock" title="This page timed out" line="صفحه مدتی باز مانده بود. دوباره بارگذاری کنید و ادامه بدهید." action="Refresh" :href="url()->previous(url('/'))" />
