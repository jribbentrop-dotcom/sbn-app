@extends('layouts.admin')

@section('content')
<div class="admin-header">
    <h1>Orders</h1>
</div>

<div class="admin-content">
    @if($orders->count() > 0)
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Order #</th>
                    <th>Email</th>
                    <th>Total</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th>Items</th>
                </tr>
            </thead>
            <tbody>
                @foreach($orders as $order)
                <tr>
                    <td>#{{ $order->id }}</td>
                    <td>{{ $order->guest_email }}</td>
                    <td>{{ $order->total_formatted }}</td>
                    <td>
                        <span class="badge badge-{{ $order->status }}">
                            {{ $order->status }}
                        </span>
                    </td>
                    <td>{{ $order->created_at->format('Y-m-d H:i') }}</td>
                    <td>{{ $order->items->count() }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        {{ $orders->links() }}
    @else
        <div class="empty-state">
            <p>No orders yet.</p>
        </div>
    @endif
</div>
@endsection
