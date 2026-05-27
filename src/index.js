import { render } from '@wordpress/element';
import { Card, CardHeader, CardBody } from '@wordpress/components';

const FraudDashboard = () => {
    return (
        <div style={{ padding: '20px' }}>
            <Card>
                <CardHeader>
                    <h2>🛡️ AI Fraud Detector</h2>
                </CardHeader>
                <CardBody>
                    <p>Dashboard loading...</p>
                </CardBody>
            </Card>
        </div>
    );
};

const container = document.getElementById('aifd-dashboard-root');
if ( container ) {
    render( <FraudDashboard />, container );
}