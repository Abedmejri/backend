<!-- resources/views/pdf/pv.blade.php -->
<h1>PV Content</h1>
<p>{{ $pv->content }}</p>
<p>Meeting Title: {{ $pv->meeting->title }}</p>
<p>Commission: {{ $pv->meeting->commission->name }}</p>
