<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>PV Document</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; margin: 20px; }
        h1, h2 { text-align: center; }
        .content { margin-top: 20px; white-space: pre-wrap; word-wrap: break-word; }
        .attendees { margin-top: 30px; }
        .attendees ul { list-style: none; padding: 0; }
        .attendees li { margin-bottom: 5px; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
    </style>
</head>
<body>
    <h1>Procès-Verbal de Réunion</h1>
    <h2>{{ $meetingTitle }}</h2>
    <p><strong>Commission:</strong> {{ $commissionName }}</p>
    @if($meeting && $meeting->date)
        <p><strong>Date:</strong> {{ \Carbon\Carbon::parse($meeting->date)->format('d/m/Y H:i') }}</p>
    @endif
    @if($meeting && $meeting->location)
        <p><strong>Lieu:</strong> {{ $meeting->location }}</p>
    @endif

    <div class="content">
        <h2>Contenu du PV</h2>
        {!! nl2br(e($content)) !!}
    </div>

    @if($users && $users->count() > 0)
        <div class="attendees">
            <h3>Participants:</h3>
            <ul>
                @foreach($users as $user)
                    <li>{{ $user->name }} {{ $user->prenom ?? '' }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if($pv && $pv->created_at)
        <p style="text-align: right; margin-top: 40px;"><em>Généré le: {{ $pv->created_at->format('d/m/Y H:i') }}</em></p>
    @endif
</body>
</html>