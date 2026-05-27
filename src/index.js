import { render, useState, useEffect } from '@wordpress/element';
import { Card, CardHeader, CardBody, Button, Spinner } from '@wordpress/components';

// ── API Helper ─────────────────────────────────
const api = {
    get: ( endpoint ) => fetch( `${aifdData.apiUrl}${endpoint}`, {
        headers: { 'X-WP-Nonce': aifdData.nonce }
    }).then( r => r.json() ),

    post: ( endpoint, body ) => fetch( `${aifdData.apiUrl}${endpoint}`, {
        method: 'POST',
        headers: {
            'X-WP-Nonce': aifdData.nonce,
            'Content-Type': 'application/json'
        },
        body: JSON.stringify( body )
    }).then( r => r.json() )
};

// ── Score Bar Component ────────────────────────
const ScoreBar = ({ score }) => {
    const color = score >= 70 ? '#ef4444' : score >= 40 ? '#f59e0b' : '#10b981';
    return (
        <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
            <div style={{
                flex: 1, height: '6px',
                background: '#f1f5f9', borderRadius: '3px'
            }}>
                <div style={{
                    width: `${score}%`, height: '6px',
                    background: color, borderRadius: '3px',
                    transition: 'width 0.3s ease'
                }} />
            </div>
            <span style={{
                fontFamily: 'monospace', fontSize: '12px',
                fontWeight: '600', color, minWidth: '25px'
            }}>
                {score}
            </span>
        </div>
    );
};

// ── Decision Badge Component ───────────────────
const Badge = ({ decision }) => {
    const styles = {
        BLOCK:    { bg: '#fef2f2', color: '#ef4444', border: '#fecaca', label: '🚫 Block' },
        REVIEW:   { bg: '#fffbeb', color: '#f59e0b', border: '#fde68a', label: '⚠️ Review' },
        ALLOW:    { bg: '#f0fdf4', color: '#10b981', border: '#bbf7d0', label: '✅ Allow' },
        APPROVED: { bg: '#f0fdf4', color: '#10b981', border: '#bbf7d0', label: '✅ Approved' },
        BLOCKED:  { bg: '#fef2f2', color: '#ef4444', border: '#fecaca', label: '🚫 Blocked' },
    };
    const s = styles[decision] || styles.REVIEW;
    return (
        <span style={{
            padding: '3px 10px', borderRadius: '20px',
            fontSize: '11px', fontWeight: '600',
            background: s.bg, color: s.color,
            border: `1px solid ${s.border}`
        }}>
            {s.label}
        </span>
    );
};

// ── Stat Card Component ────────────────────────
const StatCard = ({ value, label, color, prefix = '' }) => (
    <div style={{
        background: '#fff', border: '1px solid #e2e8f0',
        borderRadius: '12px', padding: '16px 18px',
        borderTop: `3px solid ${color}`, flex: 1
    }}>
        <div style={{
            fontSize: '28px', fontWeight: '700', color
        }}>
            {prefix}{value}
        </div>
        <div style={{
            fontSize: '11px', color: '#94a3b8',
            textTransform: 'uppercase', letterSpacing: '.05em',
            marginTop: '4px'
        }}>
            {label}
        </div>
    </div>
);

// ── Main Dashboard Component ───────────────────
const FraudDashboard = () => {

    const [stats, setStats]     = useState( null );
    const [orders, setOrders]   = useState( [] );
    const [loading, setLoading] = useState( true );
    const [filter, setFilter]   = useState( 'ALL' );
    const [acting, setActing]   = useState( null );

    // Fetch data on load
    useEffect( () => {
        fetchData();
    }, [] );

    const fetchData = async () => {
        setLoading( true );
        try {
            const [statsData, ordersData] = await Promise.all([
                api.get( 'stats' ),
                api.get( 'orders' )
            ]);
            setStats( statsData );
            setOrders( ordersData );
        } catch ( e ) {
            console.error( 'Failed to fetch data', e );
        }
        setLoading( false );
    };

    // Handle approve/block
    const handleAction = async ( orderId, actionType ) => {
        setActing( orderId );
        try {
            await api.post( `order/${orderId}/action`, { action_type: actionType } );
            await fetchData(); // Refresh data
        } catch ( e ) {
            console.error( 'Action failed', e );
        }
        setActing( null );
    };

    // Filter orders
    const filteredOrders = orders.filter( order => {
        if ( filter === 'ALL' )    return true;
        if ( filter === 'BLOCK' )  return order.decision === 'BLOCK' || order.decision === 'BLOCKED';
        if ( filter === 'REVIEW' ) return order.decision === 'REVIEW';
        if ( filter === 'ALLOW' )  return order.decision === 'ALLOW' || order.decision === 'APPROVED';
        return true;
    });

    return (
        <div style={{ padding: '20px', maxWidth: '1200px', fontFamily: 'DM Sans, sans-serif' }}>

            {/* Header */}
            <div style={{
                display: 'flex', alignItems: 'center',
                justifyContent: 'space-between', marginBottom: '24px'
            }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
                    <div style={{
                        width: '40px', height: '40px',
                        background: 'linear-gradient(135deg,#f97316,#ef4444)',
                        borderRadius: '10px', display: 'flex',
                        alignItems: 'center', justifyContent: 'center',
                        fontSize: '20px'
                    }}>🛡️</div>
                    <div>
                        <h1 style={{ margin: 0, fontSize: '22px', fontWeight: '600', color: '#1e293b' }}>
                            AI Fraud Detector
                        </h1>
                        <p style={{ margin: '2px 0 0', fontSize: '13px', color: '#64748b' }}>
                            WooCommerce Protection Dashboard
                        </p>
                    </div>
                </div>
                <div style={{
                    padding: '6px 14px', background: '#eff6ff',
                    border: '1px solid #bfdbfe', borderRadius: '20px',
                    fontSize: '12px', color: '#3b82f6'
                }}>
                    ● Live Protection Active
                </div>
            </div>

            {/* Stats */}
            {loading ? (
                <div style={{ textAlign: 'center', padding: '40px' }}>
                    <Spinner />
                </div>
            ) : (
                <>
                    <div style={{ display: 'flex', gap: '12px', marginBottom: '24px' }}>
                        <StatCard value={stats?.blocked ?? 0} label="Blocked Orders"  color="#ef4444" />
                        <StatCard value={stats?.review ?? 0}  label="Need Review"     color="#f59e0b" />
                        <StatCard value={stats?.allowed ?? 0} label="Approved"        color="#10b981" />
                        <StatCard value={Number(stats?.saved ?? 0).toFixed(0)} label="Fraud Prevented" color="#6366f1" prefix="$" />
                    </div>

                    {/* Filter Tabs */}
                    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '12px' }}>
                        <span style={{
                            fontSize: '12px', fontWeight: '600', color: '#64748b',
                            textTransform: 'uppercase', letterSpacing: '.06em'
                        }}>
                            Flagged Orders ({filteredOrders.length})
                        </span>
                        <div style={{ display: 'flex', gap: '6px' }}>
                            {['ALL', 'BLOCK', 'REVIEW', 'ALLOW'].map( f => (
                                <button key={f} onClick={() => setFilter(f)} style={{
                                    padding: '5px 12px', borderRadius: '6px',
                                    fontSize: '11px', fontWeight: '500',
                                    cursor: 'pointer', border: 'none',
                                    background: filter === f ? '#1e3a5f' : '#f1f5f9',
                                    color: filter === f ? '#60a5fa' : '#64748b',
                                    transition: 'all .15s'
                                }}>
                                    {f}
                                </button>
                            ))}
                        </div>
                    </div>

                    {/* Orders Table */}
                    <div style={{
                        background: '#fff', border: '1px solid #e2e8f0',
                        borderRadius: '12px', overflow: 'hidden'
                    }}>
                        {/* Table Header */}
                        <div style={{
                            display: 'grid',
                            gridTemplateColumns: '70px 1fr 160px 110px 100px 100px 160px',
                            padding: '10px 16px', background: '#f8fafc',
                            borderBottom: '1px solid #e2e8f0',
                            fontSize: '11px', fontWeight: '600',
                            color: '#64748b', textTransform: 'uppercase',
                            letterSpacing: '.05em'
                        }}>
                            <div>Order</div>
                            <div>Customer</div>
                            <div>AI Score</div>
                            <div>Decision</div>
                            <div>Countries</div>
                            <div>Amount</div>
                            <div>Actions</div>
                        </div>

                        {/* Orders */}
                        {filteredOrders.length === 0 ? (
                            <div style={{
                                padding: '40px', textAlign: 'center',
                                color: '#94a3b8', fontSize: '14px'
                            }}>
                                ✅ No flagged orders — your store is safe!
                            </div>
                        ) : (
                            filteredOrders.map( order => (
                                <div key={order.id} style={{
                                    display: 'grid',
                                    gridTemplateColumns: '70px 1fr 160px 110px 100px 100px 160px',
                                    padding: '14px 16px',
                                    borderBottom: '1px solid #f1f5f9',
                                    alignItems: 'center',
                                    transition: 'background .15s'
                                }}
                                onMouseEnter={e => e.currentTarget.style.background = '#f8fafc'}
                                onMouseLeave={e => e.currentTarget.style.background = 'transparent'}
                                >
                                    {/* Order ID */}
                                    <div style={{
                                        fontFamily: 'monospace', fontSize: '13px',
                                        color: '#3b82f6', fontWeight: '600'
                                    }}>
                                        #{order.id}
                                    </div>

                                    {/* Customer */}
                                    <div>
                                        <div style={{ fontSize: '13px', color: '#1e293b', fontWeight: '500' }}>
                                            {order.customer_name}
                                        </div>
                                        <div style={{ fontSize: '11px', color: '#94a3b8', marginTop: '2px' }}>
                                            {order.customer_email}
                                        </div>
                                    </div>

                                    {/* Score */}
                                    <ScoreBar score={order.risk_score} />

                                    {/* Decision */}
                                    <Badge decision={order.decision} />

                                    {/* Countries */}
                                    <div style={{
                                        fontFamily: 'monospace', fontSize: '12px', color: '#94a3b8'
                                    }}>
                                        {order.billing_country}→{order.shipping_country}
                                    </div>

                                    {/* Amount */}
                                    <div style={{
                                        fontFamily: 'monospace', fontSize: '13px',
                                        fontWeight: '600', color: '#1e293b'
                                    }}>
                                        ${order.total}
                                    </div>

                                    {/* Actions */}
                                    <div style={{ display: 'flex', gap: '6px' }}>
                                        {acting === order.id ? (
                                            <Spinner />
                                        ) : (
                                            <>
                                                <button onClick={() => handleAction(order.id, 'approve')} style={{
                                                    padding: '4px 10px', borderRadius: '6px',
                                                    fontSize: '11px', fontWeight: '500',
                                                    cursor: 'pointer', border: '1px solid #bbf7d0',
                                                    background: '#f0fdf4', color: '#10b981'
                                                }}>
                                                    ✅ Approve
                                                </button>
                                                <button onClick={() => handleAction(order.id, 'block')} style={{
                                                    padding: '4px 10px', borderRadius: '6px',
                                                    fontSize: '11px', fontWeight: '500',
                                                    cursor: 'pointer', border: '1px solid #fecaca',
                                                    background: '#fef2f2', color: '#ef4444'
                                                }}>
                                                    🚫 Block
                                                </button>
                                            </>
                                        )}
                                    </div>

                                </div>
                            ))
                        )}
                    </div>

                    {/* AI Reason Detail */}
                    {filteredOrders.length > 0 && (
                        <div style={{
                            marginTop: '16px', padding: '14px 16px',
                            background: '#fff', border: '1px solid #e2e8f0',
                            borderRadius: '12px'
                        }}>
                            <div style={{
                                fontSize: '11px', fontWeight: '600', color: '#64748b',
                                textTransform: 'uppercase', letterSpacing: '.06em',
                                marginBottom: '8px'
                            }}>
                                Latest AI Analysis
                            </div>
                            <div style={{
                                fontSize: '13px', color: '#475569', lineHeight: '1.6'
                            }}>
                                <strong style={{ color: '#ef4444' }}>Order #{filteredOrders[0].id}:</strong>{' '}
                                {filteredOrders[0].reason || 'No AI reason available'}
                            </div>
                        </div>
                    )}
                </>
            )}
        </div>
    );
};

// ── Mount React App ────────────────────────────
const container = document.getElementById( 'aifd-dashboard-root' );
if ( container ) {
    render( <FraudDashboard />, container );
}